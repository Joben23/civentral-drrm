<?php

namespace App\Repositories;

class UserRepository
{
    private $db;
    private bool $resolved = false;

    public function __construct($db)
    {
        $this->db = $db;
    }

    public function getUserWithRelations($userId, $employeeId = null)
    {
        $db = $this->database();
        if ($db === null) {
            return null;
        }

        $sql = "
            SELECT 
                u.*,
                r.role_name, r.role_prefix, r.is_global_access, r.is_superadmin, r.department_id AS role_dept_id,
                p.position_name, p.department_id,
                d.department_name, d.department_code
            FROM users u
            LEFT JOIN roles r ON u.role_id = r.role_id
            LEFT JOIN positions p ON u.position_id = p.position_id
            LEFT JOIN departments d ON p.department_id = d.department_id
            WHERE ";
            
        $params = [];
        if ($userId) {
            $sql .= "u.user_id = :id";
            $params['id'] = $userId;
        } else {
            $sql .= "u.employee_id = :id";
            $params['id'] = $employeeId;
        }

        $results = $db->query($sql, $params);
        return !empty($results) ? $results[0] : null;
    }

    private function database()
    {
        if (!$this->resolved && is_callable($this->db)) {
            $this->db = ($this->db)();
        }
        $this->resolved = true;

        return $this->db;
    }
}
