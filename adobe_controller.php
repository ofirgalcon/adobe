<?php

use Symfony\Component\Yaml\Yaml;

/**
 * Adobe_controller class
 *
 * @package adobe
 * @author tuxudo
 * 
 * Security Features:
 * - Input validation with strict length limits
 * - CSRF protection for POST requests
 * - Rate limiting to prevent abuse
 * - Security headers (X-Frame-Options, X-Content-Type-Options, etc.)
 * - Error handling without information disclosure
 * - SQL injection protection via parameterized queries
 * - Client check-in compatibility (allows $GLOBALS['auth'] == 'report')
 **/
class Adobe_controller extends Module_controller
{
    public function __construct()
    {
        $this->module_path = dirname(__FILE__);
        
        // Set security headers for all responses
        $this->setSecurityHeaders();
    }
    
    /**
     * Set security headers to prevent common attacks
     * Only apply to web interface requests, not client check-ins
     */
    private function setSecurityHeaders()
    {
        // Only set security headers for web interface requests
        // Skip for client check-ins to avoid interference
        if ($this->isClientCheckin()) {
            return;
        }
        
        // Prevent clickjacking
        header('X-Frame-Options: DENY');
        
        // Prevent MIME type sniffing
        header('X-Content-Type-Options: nosniff');
        
        // Enable XSS protection
        header('X-XSS-Protection: 1; mode=block');
        
        // Referrer policy
        header('Referrer-Policy: strict-origin-when-cross-origin');
    }
    
    /**
     * Check if this is a client check-in request (no authentication required)
     */
    private function isClientCheckin()
    {
        // Client check-ins use $GLOBALS['auth'] == 'report'
        // This allows MunkiReport clients to submit data without web authentication
        return isset($GLOBALS['auth']) && $GLOBALS['auth'] == 'report';
    }
    
    /**
     * Simple rate limiting to prevent abuse
     */
    private function checkRateLimit($action, $max_requests, $time_window)
    {
        $rate_limit_key = 'rate_limit_' . $action . '_' . $_SERVER['REMOTE_ADDR'];
        $current_time = time();
        
        // Get current request count from session
        $requests = isset($_SESSION[$rate_limit_key]) ? $_SESSION[$rate_limit_key] : 0;
        $last_reset = isset($_SESSION[$rate_limit_key . '_reset']) ? $_SESSION[$rate_limit_key . '_reset'] : 0;
        
        // Reset counter if time window has passed
        if ($current_time - $last_reset > $time_window) {
            $requests = 0;
            $last_reset = $current_time;
        }
        
        // Check if limit exceeded
        if ($requests >= $max_requests) {
            http_response_code(429); // Too Many Requests
            die(json_encode(['success' => false, 'error' => 'Rate limit exceeded. Please try again later.']));
        }
        
        // Increment request count
        $_SESSION[$rate_limit_key] = $requests + 1;
        $_SESSION[$rate_limit_key . '_reset'] = $last_reset;
    }

    /**
     * Default method
     *
     * @author AvB
     **/
    public function index()
    {
        echo "You've loaded the adobe module!";
    }

    /**
    * Retrieve data in json format
    *
    * @return void
    * @author tuxudo
    **/
    public function get_tab_data($serial_number = '')
    {
        $obj = new View();

        // Allow client check-ins (no authentication required) OR authorized web users
        if (! $this->authorized() && ! $this->isClientCheckin()) {
            $obj->view('json', array('msg' => 'Not authorized'));
            return;
        }

        // Enhanced input validation for serial number
        // Only allow alphanumeric characters, hyphens, and underscores
        // Maximum length of 50 characters to prevent buffer overflow attacks
        if (empty($serial_number) || strlen($serial_number) > 50) {
            $obj->view('json', array('msg' => array()));
            return;
        }
        
        // Strict validation: only allow valid serial number characters
        if (!preg_match('/^[A-Za-z0-9_\-]+$/', $serial_number)) {
            $obj->view('json', array('msg' => array()));
            return;
        }

        if (!authorized_for_serial($serial_number)) {
            $obj->view('json', array('msg' => 'Not authorized', 'status_code' => 403));
            return;
        }

        $queryobj = new Adobe_model();
        
        // Get database connection info for cross-database compatibility
        $connection = conf('connection');
        $is_mysql = has_mysql_db($connection);
        $is_sqlite = has_sqlite_db($connection);
        
        // Build database-specific SQL for year_edition sorting
        if ($is_mysql) {
            // MySQL syntax
            $sql = "SELECT * FROM adobe WHERE serial_number = ? ORDER BY 
                    CASE 
                        WHEN year_edition REGEXP 'CC [0-9]{4}' THEN CAST(SUBSTRING(year_edition, 4) AS UNSIGNED)
                        ELSE 0 
                    END DESC, 
                    app_name ASC";
        } elseif ($is_sqlite) {
            // SQLite syntax - use LIKE instead of REGEXP, CAST to INTEGER instead of UNSIGNED
            $sql = "SELECT * FROM adobe WHERE serial_number = ? ORDER BY 
                    CASE 
                        WHEN year_edition LIKE 'CC %' AND substr(year_edition, 4) GLOB '[0-9][0-9][0-9][0-9]' THEN CAST(substr(year_edition, 4) AS INTEGER)
                        ELSE 0 
                    END DESC, 
                    app_name ASC";
        } else {
            // Fallback for other databases
            $sql = "SELECT * FROM adobe WHERE serial_number = ? ORDER BY app_name ASC";
        }
        
        $adobe_tab = $queryobj->query($sql, [$serial_number]);
        
        $obj->view('json', array('msg' => $adobe_tab));
    }

    /**
     * Get list data for widgets
     *
     * @param string $column
     * @return void
     **/
    public function get_list($column = '')
    {
        // Enhanced input validation for column parameter
        // Only allow valid column names with strict length limits
        if (empty($column) || strlen($column) > 20) {
            jsonView([]);
            return;
        }
        
        // Whitelist allowed columns - strict validation
        $allowed_columns = [
            'app_name', 'sapcode', 'base_version', 'year_edition', 'installed_version', 
            'latest_version', 'description', 'is_up_to_date'
        ];
        
        // Strict validation against whitelist
        if (!in_array($column, $allowed_columns, true)) {
            jsonView([]);
            return;
        }
        
        $queryobj = new Adobe_model();
        
        // Get database connection info for cross-database compatibility
        $connection = conf('connection');
        $is_mysql = has_mysql_db($connection);
        $is_sqlite = has_sqlite_db($connection);
        
        // Special handling for is_up_to_date boolean column
        if ($column === 'is_up_to_date') {
            $sql = "SELECT 
                        CASE 
                            WHEN is_up_to_date = 1 THEN 'Up to Date'
                            WHEN is_up_to_date = 0 THEN 'Update Available'
                            WHEN is_up_to_date = '' OR is_up_to_date IS NULL THEN 'Unknown'
                            ELSE 'Unknown'
                        END AS label,
                        COUNT(*) AS count 
                    FROM adobe 
                    LEFT JOIN reportdata USING (serial_number)
                    ".get_machine_group_filter()."
                    GROUP BY is_up_to_date 
                    ORDER BY count DESC";
        } 
        // Special handling for year_edition column - sort by year descending
        elseif ($column === 'year_edition') {
            if ($is_mysql) {
                // MySQL syntax
                $sql = "SELECT year_edition AS label, COUNT(*) AS count 
                        FROM adobe 
                        LEFT JOIN reportdata USING (serial_number)
                        ".get_machine_group_filter()."
                        AND year_edition IS NOT NULL 
                        AND year_edition != ''
                        GROUP BY year_edition 
                        ORDER BY 
                            CASE 
                                WHEN year_edition REGEXP 'CC [0-9]{4}' THEN CAST(SUBSTRING(year_edition, 4) AS UNSIGNED)
                                ELSE 0 
                            END DESC, 
                            year_edition ASC";
            } elseif ($is_sqlite) {
                // SQLite syntax - use LIKE instead of REGEXP, CAST to INTEGER instead of UNSIGNED
                $sql = "SELECT year_edition AS label, COUNT(*) AS count 
                        FROM adobe 
                        LEFT JOIN reportdata USING (serial_number)
                        ".get_machine_group_filter()."
                        AND year_edition IS NOT NULL 
                        AND year_edition != ''
                        GROUP BY year_edition 
                        ORDER BY 
                            CASE 
                                WHEN year_edition LIKE 'CC %' AND substr(year_edition, 4) GLOB '[0-9][0-9][0-9][0-9]' THEN CAST(substr(year_edition, 4) AS INTEGER)
                                ELSE 0 
                            END DESC, 
                            year_edition ASC";
            } else {
                // Fallback for other databases
                $sql = "SELECT year_edition AS label, COUNT(*) AS count 
                        FROM adobe 
                        LEFT JOIN reportdata USING (serial_number)
                        ".get_machine_group_filter()."
                        AND year_edition IS NOT NULL 
                        AND year_edition != ''
                        GROUP BY year_edition 
                        ORDER BY year_edition ASC";
            }
        } else {
            // Use a switch statement to safely handle different columns
            // This prevents SQL injection by avoiding direct string interpolation
            switch ($column) {
                case 'app_name':
                    $sql = "SELECT app_name AS label, COUNT(*) AS count 
                            FROM adobe 
                            LEFT JOIN reportdata USING (serial_number)
                            ".get_machine_group_filter()."
                            AND app_name IS NOT NULL 
                            AND app_name != ''
                            GROUP BY app_name 
                            ORDER BY count DESC";
                    break;
                case 'sapcode':
                    $sql = "SELECT sapcode AS label, COUNT(*) AS count 
                            FROM adobe 
                            LEFT JOIN reportdata USING (serial_number)
                            ".get_machine_group_filter()."
                            AND sapcode IS NOT NULL 
                            AND sapcode != ''
                            GROUP BY sapcode 
                            ORDER BY count DESC";
                    break;
                case 'base_version':
                    $sql = "SELECT base_version AS label, COUNT(*) AS count 
                            FROM adobe 
                            LEFT JOIN reportdata USING (serial_number)
                            ".get_machine_group_filter()."
                            AND base_version IS NOT NULL 
                            AND base_version != ''
                            GROUP BY base_version 
                            ORDER BY count DESC";
                    break;
                case 'installed_version':
                    $sql = "SELECT installed_version AS label, COUNT(*) AS count 
                            FROM adobe 
                            LEFT JOIN reportdata USING (serial_number)
                            ".get_machine_group_filter()."
                            AND installed_version IS NOT NULL 
                            AND installed_version != ''
                            GROUP BY installed_version 
                            ORDER BY count DESC";
                    break;
                case 'latest_version':
                    $sql = "SELECT latest_version AS label, COUNT(*) AS count 
                            FROM adobe 
                            LEFT JOIN reportdata USING (serial_number)
                            ".get_machine_group_filter()."
                            AND latest_version IS NOT NULL 
                            AND latest_version != ''
                            GROUP BY latest_version 
                            ORDER BY count DESC";
                    break;
                case 'description':
                    $sql = "SELECT description AS label, COUNT(*) AS count 
                            FROM adobe 
                            LEFT JOIN reportdata USING (serial_number)
                            ".get_machine_group_filter()."
                            AND description IS NOT NULL 
                            AND description != ''
                            GROUP BY description 
                            ORDER BY count DESC";
                    break;
                default:
                    // This should never happen due to whitelist validation above
                    jsonView([]);
                    return;
            }
        }
        
        jsonView($queryobj->query($sql));
    }

    /**
     * Force update of year editions for all Adobe applications
     * This method recalculates year editions based on the current version mappings
     *
     * @return void
     **/
    public function force_update_year_editions()
    {
        if (! $this->authorized('global')) {
            jsonView([
                'success' => false,
                'error' => 'Unauthorized',
            ], 403);
            return;
        }

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            jsonView([
                'success' => false,
                'error' => 'POST required',
            ], 405);
            return;
        }

        verifyCSRF();

        // Rate limiting: prevent abuse of this method
        $this->checkRateLimit('force_update_year_editions', 5, 300); // 5 requests per 5 minutes

        try {
            $queryobj = new Adobe_model();
            
            // Get all Adobe records that need year edition updates
            $sql = "SELECT id, serial_number, app_name, base_version, installed_version, year_edition 
                    FROM adobe 
                    WHERE app_name IS NOT NULL 
                    AND (base_version IS NOT NULL OR installed_version IS NOT NULL)";
            
            $records = $queryobj->query($sql);
            $updated = 0;
            $total = count($records);
            
            foreach ($records as $record) {
                $app_name = $record->app_name;
                $base_version = $record->base_version;
                $installed_version = $record->installed_version;
                
                // For Lightroom, Lightroom Classic, XD, and Substance 3D Painter, use installed version instead of base version
                $version_for_mapping = $base_version;
                if (stripos($app_name, 'Lightroom') !== false || stripos($app_name, 'XD') !== false || stripos($app_name, 'Substance 3D Painter') !== false) {
                    if (!empty($installed_version)) {
                        // Extract major version number and add .0
                        if (preg_match('/^(\d+)\./', $installed_version, $matches)) {
                            $version_for_mapping = $matches[1] . '.0';
                        }
                    }
                }
                
                // Normalize version format to x.x for most applications
                // Handle both x.x.x and x.x formats, extract just x.x
                // Note: Creative Cloud Desktop uses three-part versions and should not be normalized
                if (!empty($version_for_mapping) && stripos($app_name, 'Creative Cloud') === false) {
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
                
                // Calculate new year edition
                $new_year_edition = Adobe_model::getYearEdition($app_name, $version_for_mapping);
                
                // Update if year edition has changed
                if ($new_year_edition !== $record->year_edition) {
                    $update_sql = "UPDATE adobe SET year_edition = ? WHERE id = ?";
                    $queryobj->query($update_sql, [$new_year_edition, $record->id]);
                    $updated++;
                }
            }
            
            jsonView([
                'success' => true,
                'updated' => $updated,
                'total' => $total,
                'message' => "Updated year editions for {$updated} of {$total} records"
            ]);
            
        } catch (Exception $e) {
            // Log the full error for debugging (server-side only)
            error_log("Adobe force_update_year_editions error: " . $e->getMessage());
            
            // Return generic error message to prevent information disclosure
            jsonView([
                'success' => false,
                'error' => 'An error occurred while updating year editions. Please check the server logs for details.'
            ]);
        }
    }

    /**
     * Adobe admin page entrypoint.
     *
     * @return void
     */
    public function adobe_admin()
    {
        if (! $this->authorized('global')) {
            http_response_code(403);
            die('<html><head><title>403 Forbidden</title></head><body><h1>Forbidden</h1><p>Admin access required.</p></body></html>');
        }

        $obj = new View();
        $obj->view('adobe_admin', [], $this->module_path.'/views/');
    }

    /**
     * Default admin route alias.
     *
     * @return void
     */
    public function admin()
    {
        $this->adobe_admin();
    }

    /**
     * Build mapping health payload.
     *
     * @return array
     */
    private function buildMappingHealthData()
    {
        $mapping_path = $this->module_path . '/adobe_year_edition_map.yml';

        if (! file_exists($mapping_path)) {
            return [
                'success' => false,
                'error' => 'Mapping file not found',
                'path' => $mapping_path,
            ];
        }

        if (! is_readable($mapping_path)) {
            return [
                'success' => false,
                'error' => 'Mapping file is not readable',
                'path' => $mapping_path,
            ];
        }

        try {
            $mapping_data = Yaml::parseFile($mapping_path);

            if (! is_array($mapping_data)) {
                return [
                    'success' => false,
                    'error' => 'Mapping YAML is not a valid object',
                    'path' => $mapping_path,
                ];
            }

            $has_variations = isset($mapping_data['app_variations']) && is_array($mapping_data['app_variations']);
            $has_versions = isset($mapping_data['version_mappings']) && is_array($mapping_data['version_mappings']);

            if (! $has_variations || ! $has_versions) {
                return [
                    'success' => false,
                    'error' => 'Mapping YAML missing required keys',
                    'path' => $mapping_path,
                    'required_keys' => ['app_variations', 'version_mappings'],
                ];
            }

            return [
                'success' => true,
                'path' => $mapping_path,
                'app_count' => count($mapping_data['version_mappings']),
                'variation_count' => count($mapping_data['app_variations']),
            ];
        } catch (Exception $e) {
            error_log('Adobe mapping parse error: ' . $e->getMessage());
            return [
                'success' => false,
                'error' => 'Failed to parse mapping YAML',
                'path' => $mapping_path,
            ];
        }
    }

    /**
     * Get Adobe admin status data.
     *
     * @return void
     */
    public function get_admin_data()
    {
        if (! $this->authorized('global')) {
            http_response_code(403);
            jsonView([
                'success' => false,
                'error' => 'Unauthorized',
            ]);
            return;
        }

        $mapping_health = $this->buildMappingHealthData();
        $data = [
            'mapping_health' => $mapping_health,
        ];

        if (isset($mapping_health['path']) && file_exists($mapping_health['path'])) {
            $data['mapping_mtime'] = date('Y-m-d H:i:s', filemtime($mapping_health['path']));
        }

        jsonView($data);
    }

    /**
     * Validate Adobe year edition mapping YAML file.
     *
     * @return void
     */
    public function get_mapping_health()
    {
        if (! $this->authorized('global')) {
            http_response_code(403);
            jsonView([
                'success' => false,
                'error' => 'Unauthorized',
            ]);
            return;
        }

        jsonView($this->buildMappingHealthData());
    }

} // End class Adobe_controller
