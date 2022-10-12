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
        $databasesUpdated = array();
        $databasesNotUpdated = array();
        foreach ($dbData as $table => $tableData) {
            /*check tableVersion*/
            $registeredVersion = get_option('flxVersion_' . $table) ?: 0;
            $currentVersion = isset($tableData['version']) ? $tableData['version'] : strtotime('now');

            // If there is no version registered or there is a mismatch between versions, prepare the sql query
            if ($currentVersion != $registeredVersion || $registeredVersion === 0) {
                require_once ABSPATH . 'wp-admin/includes/upgrade.php';
                global $wpdb;
                $charset_collate = $wpdb->get_charset_collate();
                $sqlInsertString = $tableKeys = $foreignKeys = array();
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
                        $foreignKeys[$tableKey] = $foreignKey['ref'];
                    }
                }



                $sql = 'CREATE TABLE IF NOT EXISTS ' . $wpdb->base_prefix . $table . ' (' . implode(',', $sqlInsertString) . ') ' . $charset_collate;
                // If the case was a version mismatch, alter the table 
                dbDelta($sql);
                if ($currentVersion !== $registeredVersion && $registeredVersion !== 0) {
                    foreach ($tableKeys as $id => $data) {
                        $row = $wpdb->get_results("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE table_name = '$wpdb->base_prefix$table' AND column_name = '$id'");
                        if (empty($row)) {
                            $wpdb->query("ALTER TABLE $wpdb->base_prefix$table ADD $id $data");
                        }
                    }
                }
                $error = $wpdb->last_error;
                if (empty($error)) {
                    update_option('flxVersion_' . $table, $currentVersion, false);
                    $message = sprintf(__('Table "%s" just updated! Current versions is %s.', 'filox'), $table, $currentVersion);
                    $databasesUpdated[] = $message;
                    if (function_exists('filoxUpdateActivity')) {
                        filoxUpdateActivity(array('name' => 'flx', 'type' => 'dbUpdate', 'comment' => array('table' => $table, 'version' => $currentVersion)));
                    }
                    continue;
                }
                $message = sprintf(__('Table "%s" not udpated! The error is: <strong>%s</strong>.', 'filox'), $table, $error);
                $databasesNotUpdated[] = $message;
            }
        }
        if (!empty($databasesUpdated)) {
            $message = new Extend_WP_Notices();
            $message->set_message(implode('<br>', $databasesUpdated));
            $message->set_class('updated');
        }
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

    public static function get_filox_db_data($tableName, $select = '*', $where_clause = '', $orderBy = array(), $limit = '', $offset = 0, $prepare_only = false, $debug = false)
    {
        if (isset($tableName) && isset($select)) {
            // This is the format of the array to be passed in this function. Once the functionality is tested it will be removed from this function and transfered into proper documentation


            // Call wpdb to get accest to table prexis
            global $wpdb;

            // Add the table prefix on the passed in datatable
            $tableName = "{$wpdb->prefix}$tableName";

            // If "select" is an array implode it with seperating commas. If select was not an array select everything
            $select = is_array($select) ? implode(',', $select) : "*";
            $sql = array();
            // Prepare the SQL query
            $sql[] = "SELECT {$select} FROM {$tableName}";

            // Pass in the where_clause array to prepare the "WHERE" part of the SQL query
            if ($where_clause !== '' && !empty($where_clause)) {
                $sql[] = "WHERE " . self::recursiveQueryBuilder($where_clause);
            }


            /* $sql .= "WHERE content_id IN (SELECT content_id FROM wp_ewp_fields_data  WHERE meta_key = 'awm_type')";*/
            if (!empty($orderBy)) {
                $sql[] = "ORDER BY {$orderBy["column"]} {$orderBy["type"]}";
            }

            if ($limit > 0 && $limit !== '') {
                $sql[] = "LIMIT {$limit}";
            }
            if ($offset !== 0) {
                $sql[] = "OFFSET {$offset}";
            }

            $sql = implode(' ', $sql);

            if ($debug) {
                print_r($wpdb->last_error);
                echo PHP_EOL . $sql . PHP_EOL;
            }
            if ($prepare_only) {
                return $sql;
            }
            if ($wpdb->last_error === '') {
                // Query the database and return the results.
                return $wpdb->get_results($sql, ARRAY_A);
            }

            return false;
        }
    }




    public static function insert_filox_db_data($tableName, $data, $primary_key = 'id')
    {
        if ($tableName && $data && !empty($data)) {
            // Call wpdb to get accest to table prefix
            global $wpdb;

            // Add the table prefix on the passed in datatable
            $tableName = "{$wpdb->prefix}$tableName";
            $final_data = array();
            foreach ($data as $key => $value) {
                $final_data[$key] = stripslashes(maybe_serialize($value));
            }
            // insert new row
            $wpdb->insert($tableName, $final_data);
            return array(
                "id" => $wpdb->insert_id,
            );
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

    public static function update_filox_db_data($tableName, $updateClause, $where_clause = '')
    {
        // Check that the parametrs are valid
        if (isset($tableName) && isset($updateClause) && isset($where_clause)) {
            // Call wpdb to get the table prexix
            global $wpdb;

            // Add the prefix to the table name
            $tableName = "{$wpdb->prefix}{$tableName}";

            // Format the update clause
            $updateClause = self::functionToFormatTheClause($updateClause);

            // Prepare the SQL query
            $sql = "UPDATE {$tableName} SET {$updateClause} ";
            // Pass in the where_clause array to prepare the "WHERE" part of the SQL query
            if ($where_clause !== '' && !empty($where_clause)) {
                $sql .= "WHERE " . self::recursiveQueryBuilder($where_clause);
            }
            // Run the update
            $query = $wpdb->query($sql);
            return $query !== false ? 1 : 0;
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

    public static function delete_filox_db_data($tableName, $where_clause = '')
    {
        // Call wpdb to get the table prexix
        global $wpdb;

        // Add the prefix to the table name
        $tableName = "{$wpdb->prefix}{$tableName}";

        // Prepare the SQL query
        $sql = "DELETE FROM {$tableName} ";

        if ($where_clause !== '' && !empty($where_clause)) {
            // Pass in the where_clause array to prepare the "WHERE" part of the SQL query
            $sql .= "WHERE " . self::recursiveQueryBuilder($where_clause);
            // Query the database and store the results.

            return $wpdb->query($sql);
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
    public static function insert_update_filox_db_data($tableName, $data, $where_clause, $unique = false)
    {
        if (!empty($tableName)) {
            $results = self::get_filox_db_data($tableName, '*', $where_clause);
            if (!empty($results)) {
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
                            self::delete_filox_db_data($tableName, $d_where_clause);
                        }
                    }
                }
                return self::update_filox_db_data($tableName, $data, $where_clause);
            }

            return self::insert_filox_db_data($tableName, $data, $unique ?: '');
        }
        return false;
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
