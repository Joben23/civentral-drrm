<?php

declare(strict_types=1);

namespace App\Services;

use InvalidArgumentException;
use RuntimeException;

final class DrrmReliefGoodsValidationException extends RuntimeException {}

final class DrrmReliefGoodsService
{
    public function __construct(private readonly DrrmDataStoreInterface $client) {}

    public function inventory(): array
    {
        return $this->client->get('relief_items', ['select' => 'id,item_name,category,unit,current_stock,reorder_level,updated_at', 'is_active' => 'eq.true', 'order' => 'item_name.asc']);
    }
    public function destinations(): array
    {
        return [
            'barangays' => $this->client->get('barangays', ['select' => 'barangay_id,name', 'order' => 'name.asc']),
            'evacuation_centers' => $this->client->get('evacuation_centers', ['select' => 'evacuation_center_id,name', 'order' => 'name.asc']),
        ];
    }
    public function distributions(): array
    {
        return $this->client->get('relief_distributions', ['select' => 'id,destination_type,barangay_id,evacuation_center_id,status,notes,distributed_at,responsible_user_reference,relief_distribution_items(quantity,relief_item_id,relief_items(item_name,unit))', 'order' => 'distributed_at.desc', 'limit' => 100]);
    }
    public function summary(array $inventory, array $distributions): array
    {
        $low = 0; $available = 0;
        foreach ($inventory as $item) {
            $stock = (float) ($item['current_stock'] ?? 0);
            if ($stock > 0) {
                $available++;
                if ($stock <= (float) ($item['reorder_level'] ?? 0)) $low++;
            }
        }
        return ['total_items' => count($inventory), 'available_items' => $available, 'low_stock_items' => $low, 'total_distributions' => count($distributions)];
    }
    public function receive(array $input, string $actor): array
    {
        $itemId = $this->uuid($input['item_id'] ?? null, 'Relief item is required.');
        $quantity = $this->positiveQuantity($input['quantity'] ?? null);
        return $this->rpc('receive_relief_stock', ['p_item_id' => $itemId, 'p_quantity' => $quantity, 'p_actor_reference' => $actor, 'p_source' => $this->optionalText($input['source'] ?? null, 180), 'p_reference_note' => $this->optionalText($input['reference_note'] ?? null, 500)]);
    }
    public function createItem(array $input): array
    {
        $name = $this->requiredText($input['item_name'] ?? null, 180, 'Item name is required.');
        $category = $this->requiredText($input['category'] ?? null, 100, 'Category is required.');
        $unit = $this->requiredText($input['unit'] ?? null, 40, 'Unit is required.');
        $reorderLevel = $input['reorder_level'] ?? 0;
        if (!is_numeric($reorderLevel) || (float) $reorderLevel < 0) throw new DrrmReliefGoodsValidationException('Reorder level must not be negative.');
        $created = $this->client->post('relief_items', ['item_name' => $name, 'category' => $category, 'unit' => $unit, 'reorder_level' => number_format((float) $reorderLevel, 3, '.', '')], ['select' => 'id,item_name,category,unit,current_stock,reorder_level,updated_at']);
        if (count($created) !== 1 || !is_array($created[0])) throw new RuntimeException('Item was not created.');
        return $created[0];
    }
    public function release(array $input, string $actor): array
    {
        $type = strtoupper(trim((string) ($input['destination_type'] ?? '')));
        if (!in_array($type, ['BARANGAY', 'EVACUATION_CENTER'], true)) throw new DrrmReliefGoodsValidationException('Destination is required.');
        $destinationId = $this->uuid($input['destination_id'] ?? null, 'Destination is required.');
        $items = $input['items'] ?? null;
        if (!is_array($items) || $items === []) throw new DrrmReliefGoodsValidationException('At least one relief item is required.');
        $validated = [];
        foreach ($items as $item) {
            if (!is_array($item)) throw new DrrmReliefGoodsValidationException('Invalid relief item.');
            $validated[] = ['item_id' => $this->uuid($item['item_id'] ?? null, 'Relief item is required.'), 'quantity' => $this->positiveQuantity($item['quantity'] ?? null)];
        }
        $availableStock = [];
        foreach ($this->inventory() as $inventoryItem) {
            if (isset($inventoryItem['id'])) $availableStock[(string) $inventoryItem['id']] = (float) ($inventoryItem['current_stock'] ?? 0);
        }
        foreach ($validated as $item) {
            if (!array_key_exists($item['item_id'], $availableStock)) throw new DrrmReliefGoodsValidationException('Relief item not found.');
            if ($availableStock[$item['item_id']] < (float) $item['quantity']) throw new DrrmReliefGoodsValidationException('Insufficient stock.');
        }
        return $this->rpc('release_relief_distribution', ['p_client_reference' => $this->reference($input['client_reference'] ?? null), 'p_destination_type' => $type, 'p_destination_id' => $destinationId, 'p_notes' => $this->optionalText($input['notes'] ?? null, 2000), 'p_actor_reference' => $actor, 'p_items' => $validated]);
    }
    private function rpc(string $function, array $payload): array
    {
        $result = $this->client->rpc($function, $payload);
        if (isset($result[0]) && is_array($result[0])) return $result[0];
        return $result;
    }
    private function uuid(mixed $value, string $message): string
    {
        if (!is_string($value) || preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $value) !== 1) throw new DrrmReliefGoodsValidationException($message);
        return $value;
    }
    private function positiveQuantity(mixed $value): string
    {
        if (is_bool($value) || (!is_string($value) && !is_int($value) && !is_float($value)) || !is_numeric($value) || (float) $value <= 0 || (float) $value > 1000000000) throw new DrrmReliefGoodsValidationException('Quantity must be greater than zero.');
        return number_format((float) $value, 3, '.', '');
    }
    private function optionalText(mixed $value, int $max): ?string
    {
        if ($value === null || $value === '') return null;
        if (!is_string($value) || trim($value) === '' || strlen($value) > $max || preg_match('/[<>]/', $value) === 1) throw new DrrmReliefGoodsValidationException('Invalid text value.');
        return trim($value);
    }
    private function requiredText(mixed $value, int $max, string $message): string
    {
        if (!is_string($value) || trim($value) === '' || strlen($value) > $max || preg_match('/[<>]/', $value) === 1) throw new DrrmReliefGoodsValidationException($message);
        return trim($value);
    }
    private function reference(mixed $value): string
    {
        if (!is_string($value) || preg_match('/^[A-Za-z0-9_-]{16,100}$/', $value) !== 1) throw new DrrmReliefGoodsValidationException('A valid request reference is required.');
        return $value;
    }
}