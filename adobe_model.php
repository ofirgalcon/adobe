<?php

use CFPropertyList\CFPropertyList;
use Symfony\Component\Yaml\Yaml;

/**
 * Adobe module model
 *
 * @package munkireport
 * @author
 **/
class Adobe_model extends \Model
{
    protected static $year_edition_mapping_cache = null;

    /**
     * Load year edition mappings from YAML configuration file.
     *
     * @return array
     */
    private static function getYearEditionMappings()
    {
        if (self::$year_edition_mapping_cache !== null) {
            return self::$year_edition_mapping_cache;
        }

        $mapping_file = dirname(__FILE__) . '/adobe_year_edition_map.yml';
        if (!is_readable($mapping_file)) {
            throw new RuntimeException('Adobe mapping file is missing or unreadable: ' . $mapping_file);
        }

        $mapping_data = Yaml::parseFile($mapping_file);
        if (
            !is_array($mapping_data)
            || !isset($mapping_data['app_variations'])
            || !isset($mapping_data['version_mappings'])
            || !is_array($mapping_data['app_variations'])
            || !is_array($mapping_data['version_mappings'])
        ) {
            throw new RuntimeException('Adobe mapping file is invalid: ' . $mapping_file);
        }

        self::$year_edition_mapping_cache = $mapping_data;

        return self::$year_edition_mapping_cache;
    }

    function __construct($serial_number = '')
    {
        parent::__construct('id', 'adobe'); // Primary key, tablename
        $this->rs['id'] = '';
        $this->rs['serial_number'] = $serial_number; 
        $this->rs['app_name'] = '';
        $this->rs['sapcode'] = '';
        $this->rs['base_version'] = '';
        $this->rs['year_edition'] = '';
        $this->rs['installed_version'] = '';
        $this->rs['latest_version'] = '';
        $this->rs['description'] = '';
        $this->rs['is_up_to_date'] = '';

        if ($serial_number) {
            $this->retrieve_record($serial_number);
        }

        $this->serial_number = $serial_number;
    }

    /**
     * Compare two version strings using semantic versioning
     *
     * @param string $version1
     * @param string $version2
     * @return int Returns -1 if v1 < v2, 0 if equal, 1 if v1 > v2
     */
    public static function compareVersions($version1, $version2)
    {
        if (empty($version1) || empty($version2)) {
            return null; // Can't compare if either is empty
        }
        
        // Normalize versions by removing non-numeric/dot characters
        $v1_clean = preg_replace('/[^\d.]/', '', $version1);
        $v2_clean = preg_replace('/[^\d.]/', '', $version2);
        
        // Split by dots and convert to integers
        $v1_parts = array_map('intval', explode('.', $v1_clean));
        $v2_parts = array_map('intval', explode('.', $v2_clean));
        
        // Pad shorter version with zeros
        $max_length = max(count($v1_parts), count($v2_parts));
        $v1_parts = array_pad($v1_parts, $max_length, 0);
        $v2_parts = array_pad($v2_parts, $max_length, 0);
        
        for ($i = 0; $i < $max_length; $i++) {
            if ($v1_parts[$i] < $v2_parts[$i]) {
                return -1;
            } elseif ($v1_parts[$i] > $v2_parts[$i]) {
                return 1;
            }
        }
        
        return 0; // Versions are equal
    }

    /**
     * Get year edition from base version
     *
     * @param string $app_name
     * @param string $base_version
     * @return string
     */
    public static function getYearEdition($app_name = '', $base_version = '')
    {
        if (empty($base_version)) {
            return '';
        }

        // Normalize version format to x.x for all applications
        // Handle both x.x.x and x.x formats, extract just x.x
        $normalized_version = $base_version;
        if (!empty($normalized_version)) {
            // If it's already in x.x format, keep it
            if (preg_match('/^\d+\.\d+$/', $normalized_version)) {
                // Already in correct format, do nothing
            }
            // If it's in x.x.x format, extract x.x
            elseif (preg_match('/^(\d+\.\d+)\./', $normalized_version, $matches)) {
                $normalized_version = $matches[1];
            }
            // If it's just x format, add .0
            elseif (preg_match('/^(\d+)$/', $normalized_version, $matches)) {
                $normalized_version = $matches[1] . '.0';
            }
        }

        try {
            $mapping_data = self::getYearEditionMappings();
        } catch (\Throwable $e) {
            error_log('Adobe year edition mapping load failed: ' . $e->getMessage());
            return '';
        }

        $app_variations = $mapping_data['app_variations'];
        $version_mappings = $mapping_data['version_mappings'];

        // Clean app name for mapping lookup
        $clean_app_name = trim($app_name);
        
        // Exclude Photoshop Elements and Premiere Elements - these are standalone products, not part of Creative Cloud
        if (stripos($clean_app_name, 'Photoshop Elements') !== false || stripos($clean_app_name, 'Premiere Elements') !== false) {
            return '';
        }
        
        // Try exact match first
        if (isset($version_mappings[$clean_app_name])) {
            if (isset($version_mappings[$clean_app_name][$normalized_version])) {
                return $version_mappings[$clean_app_name][$normalized_version];
            }
        }
        
        // Try app name variations
        if (isset($app_variations[$clean_app_name])) {
            $normalized_name = $app_variations[$clean_app_name];
            if (isset($version_mappings[$normalized_name][$normalized_version])) {
                return $version_mappings[$normalized_name][$normalized_version];
            }
        }
        
        // Try partial matches for apps with longer names
        // But exclude matches where the app name contains "Elements" (standalone products)
        foreach ($version_mappings as $mapped_app => $versions) {
            if (stripos($clean_app_name, $mapped_app) !== false) {
                // Make sure we're not matching "Photoshop" inside "Photoshop Elements"
                // Check if the match is at word boundaries or if it's the full app name
                $match_pos = stripos($clean_app_name, $mapped_app);
                $after_match = substr($clean_app_name, $match_pos + strlen($mapped_app));
                // If there's text after the match, check if it starts with " Elements" (space + Elements)
                if ($after_match !== '' && stripos($after_match, ' Elements') === 0) {
                    continue; // Skip this match - it's an Elements product
                }
                if (isset($versions[$normalized_version])) {
                    return $versions[$normalized_version];
                }
            }
        }
        
        return '';
    }

    /**
     * Process data sent by postflight
     *
     * @param plist array or XML string
     * @author
     */
    public function process($plist)
    {
        // Missing payload keeps the previous rows. An empty array is a real
        // report that this Mac has no Adobe apps.
        if ($plist === null || $plist === false || $plist === '') {
            throw new Exception("Error Processing Request: No property list found", 1);
        }

        // If we received XML string, parse it
        if (is_string($plist)) {
            $parser = new CFPropertyList();
            $parser->parse($plist, CFPropertyList::FORMAT_XML);
            $plist = $parser->toArray();
        }

        if (!is_array($plist)) {
            return;
        }

        if (count($plist) === 0) {
            $this->deleteWhere('serial_number=?', $this->serial_number);
            return;
        }

        // Count valid items before deletion
        $valid_items = 0;
        foreach ($plist as $item_entry) {
            if (isset($item_entry['app_name'], $item_entry['sapcode'])) {
                $valid_items++;
            }
        }

        // Safety check: Don't delete data if no valid items found
        if ($valid_items === 0) {
            return;
        }

        // Prepare all new records first, only delete old data if everything validates
        $new_records = [];
        foreach ($plist as $item_index => $item_entry) {
            // Check if required keys exist
            if (!isset($item_entry['app_name'], $item_entry['sapcode'])) {
                continue; // Skip items without required data
            }
            
            // Input validation: check field lengths to prevent buffer overflow attacks
            if (strlen($item_entry['app_name']) > 255 || strlen($item_entry['sapcode']) > 50) {
                continue; // Skip items with excessively long data
            }

            // Check version data and calculate is_up_to_date
            $installed_version = $item_entry['installed_version'] ?? '';
            $latest_version = $item_entry['latest_version'] ?? '';
            
            if (!empty($installed_version) && !empty($latest_version)) {
                // Use proper version comparison instead of string comparison
                $comparison = self::compareVersions($installed_version, $latest_version);
                if ($comparison !== null) {
                    $item_entry['is_up_to_date'] = $comparison >= 0 ? 1 : 0;
                } else {
                    $item_entry['is_up_to_date'] = null; // NULL for unknown status
                }
            } else {
                $item_entry['is_up_to_date'] = null; // NULL for unknown status
            }

            // Prepare record data
            $record_data = [
                'serial_number' => $this->serial_number,
                'id' => ''
            ];

            // Calculate year edition from app name and version
            $app_name = $item_entry['app_name'] ?? '';
            $base_version = $item_entry['base_version'] ?? '';
            $installed_version = $item_entry['installed_version'] ?? '';
            
            // For Lightroom, Lightroom Classic, XD, and Substance 3D Painter, use installed version instead of base version
            // Extract major version from installed version (e.g., "14.4" -> "14.0")
            $version_for_mapping = $base_version;
            if (stripos($app_name, 'Lightroom') !== false || stripos($app_name, 'XD') !== false || stripos($app_name, 'Substance 3D Painter') !== false) {
                if (!empty($installed_version)) {
                    // Extract major version number and add .0
                    if (preg_match('/^(\d+)\./', $installed_version, $matches)) {
                        $version_for_mapping = $matches[1] . '.0';
                    }
                }
            }
            
            // Normalize version format to x.x for all applications
            // Handle both x.x.x and x.x formats, extract just x.x
            if (!empty($version_for_mapping)) {
                $original_version = $version_for_mapping;
                
                // If it's already in x.x format, keep it
                if (preg_match('/^\d+\.\d+$/', $version_for_mapping)) {
                    // Already in correct format, do nothing
                }
                // If it's in x.x.x format, extract x.x
                elseif (preg_match('/^(\d+\.\d+)\./', $version_for_mapping, $matches)) {
                    $version_for_mapping = $matches[1];
                }
                // If it's just x format, add .0
                elseif (preg_match('/^(\d+)$/', $version_for_mapping, $matches)) {
                    $version_for_mapping = $matches[1] . '.0';
                }
                
                
            }
            

            
            $item_entry['year_edition'] = self::getYearEdition($app_name, $version_for_mapping);

            // Process each field
            foreach ($this->rs as $key => $value) {
                if ($key === 'is_up_to_date') {
                    if (isset($item_entry[$key]) && $item_entry[$key] !== null) {
                        if (is_bool($item_entry[$key])) {
                            $record_data[$key] = $item_entry[$key] ? 1 : 0;
                        } else {
                            $record_data[$key] = $item_entry[$key];
                        }
                    } else {
                        $record_data[$key] = null;
                    }
                } elseif ($key != "serial_number" && $key != "id") {
                    if (array_key_exists($key, $item_entry) && $item_entry[$key] !== '' && $item_entry[$key] !== "{}" && $item_entry[$key] !== "[]") {
                        $record_data[$key] = $item_entry[$key];
                    } else {
                        $record_data[$key] = null;
                    }
                }
            }

            $new_records[] = $record_data;
        }

        // Only delete old data if we have valid new records to replace it
        if (!empty($new_records)) {
            $this->deleteWhere('serial_number=?', $this->serial_number);

            // Now save all the new records
            $processed_count = 0;
            foreach ($new_records as $record_data) {
                try {
                    // Reset the model for each record
                    foreach ($this->rs as $key => $value) {
                        $this->rs[$key] = isset($record_data[$key]) ? $record_data[$key] : null;
                    }

                    if (!$this->save()) {
                        throw new Exception("Failed to save Adobe item: {$record_data['app_name']}");
                    }
                    
                    $processed_count++;
                    
                } catch (Exception $e) {
                    throw $e; // Re-throw to halt processing
                }
            }
        }
    }
}
