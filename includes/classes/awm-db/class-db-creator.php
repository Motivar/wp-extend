<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * class to create/update/edit custom databases
 */

class AWM_DB_Creator
{
    private $data;
    public function __construct($data)
    {
        $this->dbUpdate($data);
    }

    /**
     * data structure
     * array('dbName'=>array(
     * 'data'=>array('dbKey'=>sqlData),
     * 'primaryKey=>'dbKey'
     * 'index=>'dbKey'
     * 'version'=>'version' =>string for version
     * ))
     */

    public function dbUpdate($dbData)
    {
        $databasesUpdated = [];
        $databasesNotUpdated = [];

        try {
            foreach ($dbData as $table => $tableData) {
                // Validate table name and data
                if (empty($table) || empty($tableData)) {
                    throw new Exception(sprintf(__('Invalid table or data for table: %s.', 'filox'), $table));
                }

                /* Check table version */
                $registeredVersion = get_option('ewp_version_' . $table) ?: 0;
                $currentVersion = isset($tableData['version']) ? $tableData['version'] : strtotime('now');

                // If there is no version registered or there is a mismatch between versions, prepare the SQL query
                if ($currentVersion != $registeredVersion || $registeredVersion === 0) {
                    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
                    global $wpdb;
                    $wpdb->show_errors();

                    $charset_collate = $wpdb->get_charset_collate();
                    $sqlInsertString = $tableKeys = $foreignKeys = [];

                    if (isset($tableData['data']) && !empty($tableData['data'])) {
                        foreach ($tableData['data'] as $tableKey => $tableSettings) {
                            $sqlInsertString[] = $tableKey . ' ' . $tableSettings;
                            $tableKeys[$tableKey] = $tableSettings;
                        }
                    }

                    if (isset($tableData['primaryKey'])) {
                        $sqlInsertString[] = 'PRIMARY KEY  (' . $tableData['primaryKey'] . ')';
                    }

                    if (isset($tableData['index'])) {
                        $sqlInsertString[] = 'INDEX(' . $tableData['index'] . ')';
                    }

                    if (isset($tableData['foreignKey']) && !empty($tableData['foreignKey'])) {
                        foreach ($tableData['foreignKey'] as $foreignKey) {
                            $sqlInsertString[] = "FOREIGN KEY ({$foreignKey['key']}) REFERENCES {$wpdb->prefix}{$foreignKey['ref']}";
                            $foreignKeys[$foreignKey['key']] = $foreignKey['ref'];
                        }
                    }

                    $sql = 'CREATE TABLE IF NOT EXISTS ' . $wpdb->base_prefix . $table . ' (' . implode(',', $sqlInsertString) . ') ' . $charset_collate;

                    // Execute the SQL query
                    dbDelta($sql);

                    // Handle version mismatch and add missing columns
                    if ($currentVersion !== $registeredVersion && $registeredVersion !== 0) {
                        foreach ($tableKeys as $id => $data) {
                            $row = $wpdb->get_results("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE table_name = '{$wpdb->base_prefix}{$table}' AND column_name = '{$id}'");
                            if (empty($row)) {
                                $wpdb->query("ALTER TABLE {$wpdb->base_prefix}{$table} ADD {$id} {$data}");
                            }
                        }
                    }

                    // Check for errors
                    if (!empty($wpdb->last_error)) {
                        $message = sprintf(__('Table "%s" not updated! The error is: <strong>%s</strong>.', 'filox'), $table, $wpdb->last_error);
                        $databasesNotUpdated[] = $message;
                        continue; // Skip to the next table
                    }

                    // Update table version
                    update_option('ewp_version_' . $table, $currentVersion, false);
                    $message = sprintf(__('Table "%s" just updated! Current version is %s.', 'filox'), $table, $currentVersion);
                    $databasesUpdated[] = $message;

                    // Trigger custom actions
                    if (function_exists('filoxUpdateActivity')) {
                        filoxUpdateActivity(['name' => 'flx', 'type' => 'dbUpdate', 'comment' => ['table' => $table, 'version' => $currentVersion]]);
                    }
                    do_action('ewp_database_updated', $table, $tableData);
                }
            }
        } catch (Exception $e) {
            error_log('Error in dbUpdate: ' . $e->getMessage());
            $databasesNotUpdated[] = sprintf(__('Error in table "%s": %s', 'filox'), $table, $e->getMessage());
        }

        // Display messages for updated tables
        if (!empty($databasesUpdated)) {
            $message = new Extend_WP_Notices();
            $message->set_message(implode('<br>', $databasesUpdated));
            $message->set_class('updated');
        }

        // Display error messages for tables that failed to update
        if (!empty($databasesNotUpdated)) {
            $error_message = new Extend_WP_Notices();
            $error_message->set_message(implode('<br>', $databasesNotUpdated));
            $error_message->set_class('error');
        }
    }

    /**
     * Runs a select query in the custom Filox DB
     * 
     * @param tableName The name of the table to run the query on
     * @param select The columns to be retrieved. Can be just "*" to select everything or a comma seperated array with the column names
     * @param where_clause An associative array containing all the information to limit the query. Contains what follows after "WHERE".
     * @param orderBy Array with the column name and the type of order (ASC or DESC). Defaults to nothing
     * @param limit The number of rows to be returned from the SQL query. Defaults to nothing
     * 
     * @see where_clause (To be implemented after testing)
     * @see orderBy
     */

    public static function get_db_data($tableName, $select = '*', $where_clause = '', $orderBy = array(), $limit = '', $offset = 0, $prepare_only = false, $debug = false)
    {
        try {
            if (!isset($tableName) || !isset($select)) {
                throw new Exception('Table name or select clause is missing.');
            }

            // Call wpdb to get access to table prefix
            global $wpdb;

            // Enable error logging for wpdb
            $wpdb->show_errors();

            // Add the table prefix to the passed-in data table
            $tableName = "{$wpdb->prefix}$tableName";

            // If "select" is an array, implode it with separating commas. If not, select everything
            $select = is_array($select) ? implode(',', $select) : "*";
            $sql = [];

            // Prepare the SQL query
            $sql[] = "SELECT {$select} FROM {$tableName}";

            // Add WHERE clause if provided
            if ($where_clause !== '' && !empty($where_clause)) {
                $sql[] = "WHERE " . self::recursiveQueryBuilder($where_clause);
            }

            // Add ORDER BY clause if provided
            if (!empty($orderBy)) {
                if (!isset($orderBy['column']) || !isset($orderBy['type'])) {
                    throw new Exception('Invalid orderBy clause. "column" and "type" are required.');
                }
                $sql[] = "ORDER BY {$orderBy['column']} {$orderBy['type']}";
            }

            // Add LIMIT and OFFSET clauses if provided
            if ($limit > 0 && $limit !== '') {
                $sql[] = "LIMIT {$limit}";
            }
            if ($offset !== 0) {
                $sql[] = "OFFSET {$offset}";
            }

            // Build the final SQL query
            $sql = implode(' ', $sql);

            // Debug mode
            if ($debug) {
                error_log("SQL Query: {$sql}");
                error_log("Last Error: {$wpdb->last_error}");
            }

            // If prepare_only is true, return the SQL query
            if ($prepare_only) {
                return $sql;
            }

            // Execute the query and check for errors
            $results = $wpdb->get_results($sql, ARRAY_A);

            if ($wpdb->last_error) {
                throw new Exception('Database query failed: ' . $wpdb->last_error);
            }

            return $results;
        } catch (Exception $e) {
            // Log the error
            error_log('SQL Error: ' . $e->getMessage());
            return false; // Return false to indicate failure
        }
    }



    public static function insert_db_data($tableName, $data, $primary_key = 'id')
    {
        try {
            if ($tableName && $data && !empty($data)) {
                // Call wpdb to get access to table prefix
                global $wpdb;

                // Enable error handling
                $wpdb->show_errors();

                // Add the table prefix to the passed-in data table
                $tableName = "{$wpdb->prefix}$tableName";
                $final_data = array();
                foreach ($data as $key => $value) {
                    $final_data[$key] = stripslashes(maybe_serialize($value));
                }

                // Insert new row
                $result = $wpdb->insert($tableName, $final_data);

                // Check for errors
                if ($result === false) {
                    throw new Exception('Database insert failed: ' . $wpdb->last_error);
                }

                return array(
                    "id" => $wpdb->insert_id,
                );
            }
            throw new Exception('Invalid table name or data.');
        } catch (Exception $e) {
            error_log('SQL Error: ' . $e->getMessage());
            return false; // Return false to indicate failure
        }
    }

    /**
     * Runs an update query in the custom Filox database
     * 
     * @param tableName The name of the table to run the query on
     * @param updateClause An array of column-value key pairs to update. Represents the new values
     * @param where_clause An associative array containing all the information to limit the query. Contains what follows after "WHERE".
     * 
     * @see where_clause (To be implemented after testing)
     */

    public static function update_db_data($tableName, $updateClause, $where_clause = '')
    {
        try {
            // Check that the parameters are valid
            if (empty($tableName) || empty($updateClause)) {
                throw new Exception('Invalid parameters: Table name and update clause are required.');
            }

            // Call wpdb to get the table prefix
            global $wpdb;

            // Enable error logging for wpdb
            $wpdb->show_errors();

            // Add the prefix to the table name
            $tableName = "{$wpdb->prefix}{$tableName}";

            // Format the update clause
            $updateClause = self::functionToFormatTheClause($updateClause);
            if (empty($updateClause)) {
                throw new Exception('Invalid update clause: It cannot be empty.');
            }

            // Prepare the SQL query
            $sql = "UPDATE {$tableName} SET {$updateClause}";

            // Add the WHERE clause if provided
            if (!empty($where_clause)) {
                $sql .= " WHERE " . self::recursiveQueryBuilder($where_clause);
            }

            // Run the update query
            $query = $wpdb->query($sql);

            // Check for errors
            if ($query === false) {
                throw new Exception('Database update failed: ' . $wpdb->last_error);
            }

            return $query; // Return the number of rows affected
        } catch (Exception $e) {
            // Log the error
            error_log('SQL Error: ' . $e->getMessage());
            return false; // Return false to indicate failure
        }
    }

    /**
     * Runs a delete query in the custom Filox database
     * 
     * @param tableName The name of the table to run the query on
     * @param where_clause An associative array containing all the information to limit the query. Contains what follows after "WHERE".
     * 
     * @see where_clause (To be implemented after testing)
     */

    public static function delete_db_data($tableName, $where_clause = '')
    {
        try {
            if (empty($tableName)) {
                throw new Exception('Table name is required.');
            }

            // Call wpdb to get the table prefix
            global $wpdb;

            // Enable error logging for wpdb
            $wpdb->show_errors();

            // Add the prefix to the table name
            $tableName = "{$wpdb->prefix}{$tableName}";

            // Prepare the SQL query
            $sql = "DELETE FROM {$tableName}";

            // Add the WHERE clause if provided
            if (!empty($where_clause)) {
                $sql .= " WHERE " . self::recursiveQueryBuilder($where_clause);
            } else {
                throw new Exception('A WHERE clause is required for DELETE operations to prevent accidental data loss.');
            }

            // Execute the query
            $result = $wpdb->query($sql);

            // Check for errors
            if ($result === false) {
                throw new Exception('Database delete failed: ' . $wpdb->last_error);
            }

            return $result; // Return the number of rows affected
        } catch (Exception $e) {
            // Log the error
            error_log('SQL Error in delete_db_data: ' . $e->getMessage());
            return false; // Return false to indicate failure
        }
    }

    /**
     * this function decides to insert or update the db data
     * @param tableName The name of the table to run the query on
     * @param updateClause An array of column-value key pairs to update. Represents the new values
     * @param where_clause An associative array containing all the information to limit the query. Contains what follows after "WHERE".
     * 
     * @see where_clause (To be implemented after testing)
     */
    public static function insert_update_db_data($tableName, $data, $where_clause = array(), $unique = false)
    {
        try {
            if (empty($tableName)) {
                throw new Exception('Table name is required.');
            }

            if (empty($data)) {
                throw new Exception('Data to insert or update is required.');
            }

            // If a WHERE clause is provided, check for existing data
            if (!empty($where_clause)) {
                $results = self::get_db_data($tableName, '*', $where_clause);

                if (!empty($results)) {
                    // Handle unique constraint if specified
                    if ($unique) {
                        foreach ($results as $result_key => $result) {
                            if ($result_key != 0) {
                                $d_where_clause = array(
                                    "clause" => array(
                                        array(
                                            "operator" => "AND",
                                            "clause" => array(
                                                array("column" => $unique, "value" => $result[$unique], "compare" => "="),
                                            )
                                        ),
                                    )
                                );
                                // Delete duplicate rows
                                self::delete_db_data($tableName, $d_where_clause);
                            }
                        }
                    }

                    // Perform update if data exists
                    $update_result = self::update_db_data($tableName, $data, $where_clause);
                    if ($update_result === false) {
                        throw new Exception('Database update failed.');
                    }

                    return $update_result; // Return the number of rows updated
                }
            }

            // Perform insert if no existing data
            $insert_result = self::insert_db_data($tableName, $data, $unique ?: '');
            if ($insert_result === false) {
                throw new Exception('Database insert failed.');
            }

            return $insert_result; // Return the ID of the inserted row
        } catch (Exception $e) {
            // Log the error
            error_log('SQL Error in insert_update_db_data: ' . $e->getMessage());
            return false; // Return false to indicate failure
        }
    }


    /**
     * Function that dynamically creates the WHERE syntax for a an SQL query
     * 
     * @param array The array that gets passed in to create the WHERE statement
     * 
     */

    private static function recursiveQueryBuilder($array, $it = 1)
    {
        if (!is_array($array)) {
            return $array;
        }

        if ($it == 1) $flxRecursiveQuery = array();

        // An array of sub queries to be connected by the connecting operator
        global $flxRecursiveQuery;

        // The sql query as a string
        $sql = '';

        // The operator that connects all the constraints together (AND,OR)
        $connectingOperator = '';

        $sqlString = '';

        $addParenthesis = true;

        if (isset($array) && !empty($array)) {
            foreach ($array as $key => $value) {
                // First operator parsed is the connecting operator
                if ($key === "operator") {
                    $connectingOperator = $value;
                }


                // Check that the value is not an empty array
                if (is_array($value) && !empty($value)) {

                    for ($i = 0; $i < sizeof($value); $i++) {
                        // The sql query as a string
                        $sql = '';

                        if ($it == 2 && $addParenthesis) {
                            $sql .= "(";
                            $addParenthesis = false;
                        }

                        if (self::array_depth($value[$i]) > 3) {
                            return self::recursiveQueryBuilder($value[$i], $it = 2);
                        }
                        // Using parentheses in any case. More parentheses is not a problem. A lack there of does.
                        $sql .= "(";

                        // Check if the operator is set. If it's not set we expect only one query and not a combination
                        $operator = isset($value[$i]["operator"]) ? $value[$i]["operator"] : '';

                        // Loop over the clause to get the column name, the value and the copmarison operator
                        foreach ($value[$i]["clause"] as $key => $clause) {

                            $val = is_string($clause["value"]) ? "'{$clause["value"]}'" : $clause["value"];
                            // Concatenate the sql query
                            if ($clause["compare"] == 'IN' || $clause["compare"] == 'NOT IN') {
                                $val = "{$clause["value"]}";
                            }
                            $sql .= "{$clause["column"]} {$clause["compare"]} {$val}";

                            // If an operator is given and there are more clauses to parse add the operator to the query
                            if ($key < (sizeof($value[$i]["clause"]) - 1) && $operator !== '') {
                                $sql .= " {$operator} ";
                            }
                        }
                        // Close the parentheses
                        $sql .= ")";

                        // Push the query to the table.

                        if ($i < sizeof($value) - 1) {
                            $sql .= " {$connectingOperator}";
                        }

                        $flxRecursiveQuery[] = $sql;
                    }

                    if ($it == 2) {
                        $flxRecursiveQuery[] = ")";
                    }

                    // Connect the sql strings by introducton the connecting operator between them
                    $sqlString = implode(" ", $flxRecursiveQuery);

                    $flxRecursiveQuery = array();
                    if ($it == 1) {
                        return $sql;
                    }

                    return $sqlString;
                    // }

                    // return " {$value["column"]} {$value["compare"]} {$value["value"]}";
                }
            }
        }
    }

    private static function functionToFormatTheClause($clause)
    {
        if (isset($clause) && !empty($clause)) {
            $setStatement = array();
            foreach ($clause as $key => $value) {
                $setStatement[] = $key . '=\'' . stripslashes(maybe_serialize($value)) . '\',';
            }
        }

        return substr(implode('', $setStatement), 0, -1);
    }

    private static function array_depth(array $array)
    {
        $max_depth = 1;

        foreach ($array as $value) {
            if (is_array($value)) {
                $depth = self::array_depth($value) + 1;

                if ($depth > $max_depth) {
                    $max_depth = $depth;
                }
            }
        }

        return $max_depth;
    }
}