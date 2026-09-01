(function (root, factory) {
  'use strict';
  const api = factory();
  if (typeof module === 'object' && module.exports) module.exports = api;
  if (root) root.CiventralDrrmOperationalData = api;
})(typeof window !== 'undefined' ? window : null, function () {
  'use strict';

  const RISK_CLASSES = Object.freeze({
    FLOOD: Object.freeze({
      LOW: Object.freeze({ code: 'LF', label: 'Low Susceptibility to Flooding', display: 'Low' }),
      MODERATE: Object.freeze({ code: 'MF', label: 'Moderate Susceptibility to Flooding', display: 'Moderate' }),
      HIGH: Object.freeze({ code: 'HF', label: 'High Susceptibility to Flooding', display: 'High' }),
      CRITICAL: Object.freeze({ code: 'VHF', label: 'Very High Susceptibility to Flooding', display: 'Very High' })
    }),
    LANDSLIDE: Object.freeze({
      LOW: Object.freeze({ code: 'LL', label: 'Low Susceptibility to Landslide', display: 'Low' }),
      MODERATE: Object.freeze({ code: 'ML', label: 'Moderate Susceptibility to Landslide', display: 'Moderate' }),
      HIGH: Object.freeze({ code: 'HL', label: 'High Susceptibility to Landslide', display: 'High' }),
      CRITICAL: Object.freeze({ code: 'VHL', label: 'Very High Susceptibility to Landslide', display: 'Very High' })
    })
  });

  function isObject(value) {
    return Boolean(value) && typeof value === 'object' && !Array.isArray(value);
  }

  function requiredString(value, label) {
    const normalized = typeof value === 'string' ? value.trim() : '';
    if (!normalized) throw new Error(label + ' is invalid.');
    return normalized;
  }

  function optionalString(value) {
    return typeof value === 'string' && value.trim() ? value.trim() : null;
  }

  function numericId(value, label) {
    const number = Number(value);
    if (!Number.isInteger(number) || number < 1) throw new Error(label + ' is invalid.');
    return number;
  }

  function assertEnvelope(payload) {
    if (!isObject(payload) || payload.success !== true || !Array.isArray(payload.data)) {
      throw new Error('Invalid operational endpoint response.');
    }
    return payload.data;
  }

  function assertPublishedWorkflow(record, kind) {
    if (!isObject(record)) throw new Error('Invalid operational record.');
    if (kind === 'barangay' || kind === 'hazard' || kind === 'fault') {
      if (Object.prototype.hasOwnProperty.call(record, 'record_status') && record.record_status !== 'ACTIVE') {
        throw new Error('An unpublished operational record was rejected.');
      }
    }
    if (kind === 'center') {
      if (Object.prototype.hasOwnProperty.call(record, 'publication_status') && record.publication_status !== 'PUBLISHED') {
        throw new Error('An unpublished operational record was rejected.');
      }
      if (Object.prototype.hasOwnProperty.call(record, 'operational_status') && record.operational_status === 'INACTIVE') {
        throw new Error('An inactive operational record was rejected.');
      }
    }
    if (kind === 'route' && Object.prototype.hasOwnProperty.call(record, 'route_status') && record.route_status !== 'APPROVED') {
      throw new Error('An unapproved operational route was rejected.');
    }
  }

  function validatePosition(position) {
    if (!Array.isArray(position) || position.length < 2
      || !Number.isFinite(Number(position[0])) || !Number.isFinite(Number(position[1]))) {
      throw new Error('Invalid GeoJSON position.');
    }
    const longitude = Number(position[0]);
    const latitude = Number(position[1]);
    if (longitude < -180 || longitude > 180 || latitude < -90 || latitude > 90) {
      throw new Error('GeoJSON position is outside WGS84 bounds.');
    }
  }

  function validateLine(line) {
    if (!Array.isArray(line) || line.length < 2) throw new Error('Invalid GeoJSON line.');
    line.forEach(validatePosition);
  }

  function validateRing(ring) {
    if (!Array.isArray(ring) || ring.length < 4) throw new Error('Invalid GeoJSON ring.');
    ring.forEach(validatePosition);
  }

  function assertGeometry(geometry, allowedTypes) {
    if (!isObject(geometry) || !allowedTypes.includes(geometry.type)
      || !Array.isArray(geometry.coordinates) || geometry.coordinates.length === 0) {
      throw new Error('Invalid operational GeoJSON geometry.');
    }
    if (geometry.type === 'Point') validatePosition(geometry.coordinates);
    if (geometry.type === 'LineString') validateLine(geometry.coordinates);
    if (geometry.type === 'MultiLineString') geometry.coordinates.forEach(validateLine);
    if (geometry.type === 'Polygon') geometry.coordinates.forEach(validateRing);
    if (geometry.type === 'MultiPolygon') {
      geometry.coordinates.forEach(function (polygon) {
        if (!Array.isArray(polygon) || polygon.length === 0) throw new Error('Invalid GeoJSON polygon.');
        polygon.forEach(validateRing);
      });
    }
    // Preserve authoritative GeoJSON longitude-latitude coordinates unchanged.
    return geometry;
  }

  function decodeNotes(value) {
    if (isObject(value)) return value;
    if (typeof value !== 'string' || value.trim() === '') return {};
    try {
      const parsed = JSON.parse(value);
      return isObject(parsed) ? parsed : {};
    } catch (error) {
      return {};
    }
  }

  function featureCollection(features) {
    return Object.freeze({ type: 'FeatureCollection', features: Object.freeze(features) });
  }

  function mapBarangays(payload) {
    const features = assertEnvelope(payload).map(function (record) {
      assertPublishedWorkflow(record, 'barangay');
      return Object.freeze({
        type: 'Feature',
        geometry: assertGeometry(record.boundary_geometry, ['Polygon', 'MultiPolygon']),
        properties: Object.freeze({
          name: requiredString(record.name, 'Barangay name'),
          barangay_code: requiredString(record.barangay_code, 'Barangay code'),
          district_code: optionalString(record.district_code),
          _barangay_id: requiredString(record.barangay_id, 'Barangay reference'),
          display_status: 'Published operational boundary'
        })
      });
    });
    return featureCollection(features);
  }

  function mapLookups(payload) {
    if (!isObject(payload) || payload.success !== true || !isObject(payload.data)
      || !Array.isArray(payload.data.hazard_types) || !Array.isArray(payload.data.risk_levels)) {
      throw new Error('Invalid operational lookup response.');
    }
    const hazardTypes = new Map();
    const riskLevels = new Map();
    payload.data.hazard_types.forEach(function (record) {
      hazardTypes.set(numericId(record.hazard_type_id, 'Hazard type ID'), {
        code: requiredString(record.code, 'Hazard type code').toUpperCase(),
        name: requiredString(record.name, 'Hazard type name')
      });
    });
    payload.data.risk_levels.forEach(function (record) {
      riskLevels.set(numericId(record.risk_level_id, 'Risk level ID'), {
        code: requiredString(record.code, 'Risk level code').toUpperCase(),
        name: requiredString(record.name, 'Risk level name')
      });
    });
    return Object.freeze({ hazardTypes: hazardTypes, riskLevels: riskLevels });
  }

  function mapHazards(payload, lookupPayload) {
    const lookups = mapLookups(lookupPayload);
    const flood = [];
    const landslide = [];

    assertEnvelope(payload).forEach(function (record) {
      assertPublishedWorkflow(record, 'hazard');
      const hazardType = lookups.hazardTypes.get(numericId(record.hazard_type_id, 'Hazard type ID'));
      const riskLevel = lookups.riskLevels.get(numericId(record.risk_level_id, 'Risk level ID'));
      if (!hazardType || !riskLevel || !RISK_CLASSES[hazardType.code]
        || !RISK_CLASSES[hazardType.code][riskLevel.code]) {
        throw new Error('Unsupported operational hazard classification.');
      }

      const classification = RISK_CLASSES[hazardType.code][riskLevel.code];
      const notes = decodeNotes(record.classification_notes);
      const noteCode = hazardType.code === 'FLOOD' ? notes.mgb_flood_code : notes.mgb_landslide_code;
      const noteLabel = hazardType.code === 'FLOOD' ? notes.mgb_flood_label : notes.mgb_landslide_label;
      if (noteCode && String(noteCode).toUpperCase() !== classification.code) {
        throw new Error('Operational hazard metadata conflicts with its published risk level.');
      }

      const feature = Object.freeze({
        type: 'Feature',
        geometry: assertGeometry(record.geometry, ['Polygon', 'MultiPolygon']),
        properties: Object.freeze({
          hazard: hazardType.name,
          mgb_code: classification.code,
          mgb_label: optionalString(noteLabel) || classification.label,
          display_risk_label: classification.display,
          source_agency: optionalString(notes.source_agency) || 'Published operational dataset'
        })
      });
      (hazardType.code === 'FLOOD' ? flood : landslide).push(feature);
    });

    return Object.freeze({ flood: featureCollection(flood), landslide: featureCollection(landslide) });
  }

  function mapFaults(payload) {
    const features = assertEnvelope(payload).map(function (record) {
      assertPublishedWorkflow(record, 'fault');
      return Object.freeze({
        type: 'Feature',
        geometry: assertGeometry(record.geometry, ['LineString', 'MultiLineString']),
        properties: Object.freeze({
          fault_name: requiredString(record.feature_name, 'Fault name'),
          feature_class: requiredString(record.feature_class, 'Fault class'),
          source_agency: 'Published operational dataset'
        })
      });
    });
    return featureCollection(features);
  }

  function barangayNamesById(barangayCollection) {
    const result = new Map();
    if (!barangayCollection || !Array.isArray(barangayCollection.features)) return result;
    barangayCollection.features.forEach(function (feature) {
      const properties = feature && feature.properties;
      if (properties && properties._barangay_id) result.set(properties._barangay_id, properties.name);
    });
    return result;
  }

  function mapEvacuationCenters(payload, barangayCollection) {
    const barangays = barangayNamesById(barangayCollection);
    const features = assertEnvelope(payload).map(function (record) {
      assertPublishedWorkflow(record, 'center');
      const centerId = requiredString(record.evacuation_center_id, 'Evacuation center reference');
      const barangayId = requiredString(record.barangay_id, 'Evacuation center barangay reference');
      const operationalStatus = requiredString(record.operational_status, 'Evacuation center status');
      return Object.freeze({
        type: 'Feature',
        geometry: assertGeometry(record.location, ['Point']),
        properties: Object.freeze({
          _evacuation_center_id: centerId,
          name: requiredString(record.name, 'Evacuation center name'),
          barangay_name: barangays.get(barangayId) || null,
          designation: 'Evacuation Center',
          location_verification_status: 'Published operational location',
          display_status: operationalStatus,
          address: optionalString(record.address),
          capacity: record.capacity !== null && record.capacity !== undefined
            && Number.isFinite(Number(record.capacity)) && Number(record.capacity) >= 0
            ? Number(record.capacity)
            : null,
          contact_phone: optionalString(record.contact_phone),
          accessibility_notes: optionalString(record.accessibility_notes),
          source_agency: optionalString(record.managing_office_name) || 'Published operational dataset'
        })
      });
    });
    return featureCollection(features);
  }

  function centerNamesById(centerCollection) {
    const result = new Map();
    if (!centerCollection || !Array.isArray(centerCollection.features)) return result;
    centerCollection.features.forEach(function (feature) {
      const properties = feature && feature.properties;
      if (properties && properties._evacuation_center_id) {
        result.set(properties._evacuation_center_id, properties.name);
      }
    });
    return result;
  }

  function mapEvacuationRoutes(payload, centerCollection) {
    const centers = centerNamesById(centerCollection);
    const features = assertEnvelope(payload).map(function (record) {
      assertPublishedWorkflow(record, 'route');
      const destinationId = requiredString(record.destination_center_id, 'Route destination reference');
      const distanceMeters = Number(record.distance_meters);
      if (!Number.isFinite(distanceMeters) || distanceMeters <= 0) {
        throw new Error('Published route distance is invalid.');
      }
      if (record.origin_location !== null && record.origin_location !== undefined) {
        assertGeometry(record.origin_location, ['Point']);
      }
      return Object.freeze({
        type: 'Feature',
        geometry: assertGeometry(record.route_geometry, ['LineString', 'MultiLineString']),
        properties: Object.freeze({
          route_name: requiredString(record.route_name, 'Route name'),
          origin_name: optionalString(record.origin_name) || 'Published route origin',
          destination_name: centers.get(destinationId) || 'Published evacuation center',
          distance_meters: distanceMeters,
          safety_notes: optionalString(record.safety_notes)
        })
      });
    });
    return featureCollection(features);
  }

  return Object.freeze({
    assertEnvelope: assertEnvelope,
    mapBarangays: mapBarangays,
    mapLookups: mapLookups,
    mapHazards: mapHazards,
    mapFaults: mapFaults,
    mapEvacuationCenters: mapEvacuationCenters,
    mapEvacuationRoutes: mapEvacuationRoutes
  });
});
