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

    /**
     * Friendly names for Adobe SAP codes (RUM-only placeholders often use sapcode as app_name).
     *
     * @return array
     */
    public static function sapAppNames()
    {
        return [
            'ACR' => 'Camera Raw plugin',
            'AEFT' => 'After Effects',
            'AICY' => 'InCopy',
            'AME' => 'Media Encoder',
            'AUDT' => 'Audition',
            'CCXP' => 'Creative Cloud Experience',
            'CHAR' => 'Character Animator',
            'COSY' => 'Core Sync',
            'DRWV' => 'Dreamweaver',
            'ECR' => 'Camera Raw Elements',
            'EORG' => 'Elements Organizer',
            'ESHR' => 'Dimension',
            'FLPR' => 'Animate',
            'FRSC' => 'Fresco',
            'HPRE' => 'Premiere Elements Welcome Screen',
            'HPSE' => 'Photoshop Elements Welcome Screen',
            'IDSN' => 'InDesign',
            'ILST' => 'Illustrator',
            'KBRG' => 'Bridge',
            'KCCC' => 'Creative Cloud Desktop',
            'LRCC' => 'Lightroom',
            'LTRM' => 'Lightroom Classic',
            'PHSP' => 'Photoshop',
            'PPRO' => 'Premiere Pro',
            'PRE' => 'Premiere Elements',
            'PRLD' => 'Prelude',
            'PSE' => 'Photoshop Elements',
            'RUSH' => 'Premiere Rush',
            'SBSTA' => 'Substance 3D Sampler',
            'SBSTD' => 'Substance 3D Designer',
            'SBSTP' => 'Substance 3D Painter',
            'SHPR' => 'Substance 3D Modeler',
            'SPRK' => 'XD',
            'STGR' => 'Substance 3D Stager',
        ];
    }

    /**
     * True when name is empty or an uppercase SAP-style token.
     *
     * @param string $name
     * @return bool
     */
    public static function looksLikeSapcode($name)
    {
        $name = trim((string) $name);
        if ($name === '') {
            return true;
        }

        return (bool) preg_match('/^[A-Z][A-Z0-9]{1,7}$/', $name);
    }

    /**
     * Prefer a real product name over a raw SAP code placeholder.
     *
     * @param string $sapcode
     * @param string $app_name
     * @return string
     */
    public static function resolveAppName($sapcode, $app_name)
    {
        $sapcode = trim((string) $sapcode);
        $app_name = (string) $app_name;
        $map = self::sapAppNames();
        $mapped = $map[$sapcode] ?? '';

        if ($mapped !== '' && self::looksLikeSapcode($app_name)) {
            return $mapped;
        }

        $trimmed = trim($app_name);
        if ($sapcode === 'PPRO' && in_array($trimmed, ['Premiere', 'Adobe Premiere'], true)) {
            return 'Premiere Pro';
        }

        return $app_name;
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
     * Normalize stored/calculated year edition values.
     * Unmapped apps use NULL in the DB; treat '' the same for comparisons.
     *
     * @param mixed $year_edition
     * @return string|null
     */
    public static function normalizeYearEditionValue($year_edition)
    {
        if ($year_edition === null) {
            return null;
        }

        $year_edition = trim((string) $year_edition);
        return $year_edition === '' ? null : $year_edition;
    }

    /**
     * Choose and normalize the version string used for year-edition mapping.
     *
     * Most apps use base_version. Lightroom, Lightroom Classic, XD, and
     * Substance 3D Painter use the major of installed_version (N.0).
     *
     * @param string $app_name
     * @param string $base_version
     * @param string $installed_version
     * @param string $sapcode
     * @return string
     */
    public static function versionForYearEditionMapping($app_name = '', $base_version = '', $installed_version = '', $sapcode = '')
    {
        $version_for_mapping = (string) $base_version;
        $sap = strtoupper(trim((string) $sapcode));

        // These products often report a stale/generic base_version; map from installed major.
        $use_installed_major = (
            stripos($app_name, 'Lightroom') !== false
            || (bool) preg_match('/\bXD\b/i', (string) $app_name)
            || stripos($app_name, 'Substance 3D Painter') !== false
            || in_array($sap, ['LRCC', 'LTRM', 'SPRK', 'SBSTP'], true)
        );

        if ($use_installed_major && $installed_version !== '' && $installed_version !== null) {
            if (preg_match('/^(\d+)\./', (string) $installed_version, $matches)) {
                $version_for_mapping = $matches[1] . '.0';
            } elseif (preg_match('/^(\d+)$/', (string) $installed_version, $matches)) {
                $version_for_mapping = $matches[1] . '.0';
            }
        }

        if ($version_for_mapping === '') {
            return '';
        }

        // Normalize to x.x for YAML keys (25.0.1 → 25.0, 25 → 25.0)
        if (preg_match('/^\d+\.\d+$/', $version_for_mapping)) {
            return $version_for_mapping;
        }
        if (preg_match('/^(\d+\.\d+)\./', $version_for_mapping, $matches)) {
            return $matches[1];
        }
        if (preg_match('/^(\d+)$/', $version_for_mapping, $matches)) {
            return $matches[1] . '.0';
        }

        return $version_for_mapping;
    }

    /**
     * Look up a year edition for an identified app map.
     * Falls back to major.0 when major.minor misses (except Creative Cloud Desktop).
     *
     * @param array $versions
     * @param string $normalized_version
     * @param string $mapped_app
     * @return string|null
     */
    private static function lookupMappedYearEdition(array $versions, $normalized_version, $mapped_app)
    {
        if (isset($versions[$normalized_version])) {
            return $versions[$normalized_version];
        }

        // CCD minors are distinct years in the YAML; do not collapse 6.5 → 6.0.
        if (stripos($mapped_app, 'Creative Cloud') !== false) {
            return null;
        }

        if (preg_match('/^(\d+)\.(\d+)$/', $normalized_version, $matches) && $matches[2] !== '0') {
            $major_version = $matches[1] . '.0';
            if (isset($versions[$major_version])) {
                return $versions[$major_version];
            }
        }

        return null;
    }

    /**
     * Get year edition from base version.
     * Throws if the mapping YAML cannot be loaded (callers must not wipe years).
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

        // versionForYearEditionMapping already normalizes; keep a safety normalize here.
        $normalized_version = $base_version;
        if (preg_match('/^\d+\.\d+$/', $normalized_version)) {
            // already x.x
        } elseif (preg_match('/^(\d+\.\d+)\./', $normalized_version, $matches)) {
            $normalized_version = $matches[1];
        } elseif (preg_match('/^(\d+)$/', $normalized_version, $matches)) {
            $normalized_version = $matches[1] . '.0';
        }

        // Let load failures propagate so process()/recalc do not silently clear years.
        $mapping_data = self::getYearEditionMappings();

        $app_variations = $mapping_data['app_variations'];
        $version_mappings = $mapping_data['version_mappings'];

        $clean_app_name = trim($app_name);

        // Standalone Elements products are not Creative Cloud year editions
        if (stripos($clean_app_name, 'Photoshop Elements') !== false || stripos($clean_app_name, 'Premiere Elements') !== false) {
            return '';
        }

        // Exact app map: miss stops here (no cross-product partial match)
        if (isset($version_mappings[$clean_app_name])) {
            $year = self::lookupMappedYearEdition(
                $version_mappings[$clean_app_name],
                $normalized_version,
                $clean_app_name
            );
            return $year !== null ? $year : '';
        }

        // Known name variation → canonical map; miss stops here
        if (isset($app_variations[$clean_app_name])) {
            $normalized_name = $app_variations[$clean_app_name];
            if (!isset($version_mappings[$normalized_name])) {
                return '';
            }
            $year = self::lookupMappedYearEdition(
                $version_mappings[$normalized_name],
                $normalized_version,
                $normalized_name
            );
            return $year !== null ? $year : '';
        }

        // Partial match for longer display names (e.g. "Adobe Photoshop 2025")
        foreach ($version_mappings as $mapped_app => $versions) {
            if (stripos($clean_app_name, $mapped_app) === false) {
                continue;
            }
            $match_pos = stripos($clean_app_name, $mapped_app);
            $after_match = substr($clean_app_name, $match_pos + strlen($mapped_app));
            if ($after_match !== '' && stripos($after_match, ' Elements') === 0) {
                continue;
            }
            $year = self::lookupMappedYearEdition($versions, $normalized_version, $mapped_app);
            if ($year !== null) {
                return $year;
            }
            // Identified this app map but version missing — do not try another product
            return '';
        }

        return '';
    }

    /**
     * Coerce a client-supplied scalar string field with a max length.
     *
     * @param mixed $value
     * @param int $max_length
     * @return string|null Null when missing/empty/non-scalar
     */
    private static function sanitizeStringField($value, $max_length)
    {
        if ($value === null || $value === '' || $value === '{}' || $value === '[]') {
            return null;
        }
        if (is_bool($value) || is_array($value) || is_object($value)) {
            return null;
        }
        if (!is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        if (strlen($value) > $max_length) {
            return substr($value, 0, $max_length);
        }

        return $value;
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

        // Fail closed on mapping problems before touching existing rows.
        self::getYearEditionMappings();

        // Prepare all new records first, only delete old data if everything validates
        $new_records = [];
        foreach ($plist as $item_entry) {
            if (!is_array($item_entry)) {
                continue;
            }
            if (!isset($item_entry['app_name'], $item_entry['sapcode'])) {
                continue;
            }
            if (!is_scalar($item_entry['app_name']) || !is_scalar($item_entry['sapcode'])) {
                continue;
            }

            $app_name_raw = trim((string) $item_entry['app_name']);
            $sapcode_raw = trim((string) $item_entry['sapcode']);
            if ($app_name_raw === '' || $sapcode_raw === '') {
                continue;
            }
            if (strlen($app_name_raw) > 255 || strlen($sapcode_raw) > 50) {
                continue;
            }

            $installed_version = self::sanitizeStringField($item_entry['installed_version'] ?? null, 255) ?? '';
            $latest_version = self::sanitizeStringField($item_entry['latest_version'] ?? null, 255) ?? '';
            $base_version = self::sanitizeStringField($item_entry['base_version'] ?? null, 255) ?? '';
            $description = self::sanitizeStringField($item_entry['description'] ?? null, 255);

            if ($installed_version !== '' && $latest_version !== '') {
                $comparison = self::compareVersions($installed_version, $latest_version);
                $is_up_to_date = ($comparison !== null) ? ($comparison >= 0 ? 1 : 0) : null;
            } else {
                $is_up_to_date = null;
            }

            $app_name = self::resolveAppName($sapcode_raw, $app_name_raw);
            $version_for_mapping = self::versionForYearEditionMapping(
                $app_name,
                $base_version,
                $installed_version,
                $sapcode_raw
            );
            // Empty → NULL below; mapping load failures throw before delete.
            $year_edition = self::normalizeYearEditionValue(
                self::getYearEdition($app_name, $version_for_mapping)
            );

            $new_records[] = [
                'serial_number' => $this->serial_number,
                'id' => '',
                'app_name' => $app_name,
                'sapcode' => $sapcode_raw,
                'base_version' => $base_version !== '' ? $base_version : null,
                'year_edition' => $year_edition,
                'installed_version' => $installed_version !== '' ? $installed_version : null,
                'latest_version' => $latest_version !== '' ? $latest_version : null,
                'description' => $description,
                'is_up_to_date' => $is_up_to_date,
            ];
        }

        // Safety check: Don't delete data if no valid items found
        if (empty($new_records)) {
            return;
        }

        $dbh = $this->getdbh();
        $dbh->beginTransaction();
        try {
            $this->deleteWhere('serial_number=?', $this->serial_number);

            foreach ($new_records as $record_data) {
                foreach ($this->rs as $key => $value) {
                    $this->rs[$key] = array_key_exists($key, $record_data) ? $record_data[$key] : null;
                }
                $this->save();
            }

            $dbh->commit();
        } catch (Exception $e) {
            if ($dbh->inTransaction()) {
                $dbh->rollBack();
            }
            throw $e;
        }
    }
}
