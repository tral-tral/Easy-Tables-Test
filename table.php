<?php
if(!defined('ABSPATH')) exit; // Exit if accessed directly

class Table{

    protected $TABLE_LIMIT = 10;
    protected $name;
    protected $columns_info;

    public function __construct( $name, $columns_info ){
        $this->name = $name;
        $this->columns_info = $columns_info;
        $this->installtable();
    }


    private function get_columns_definition($columns) {
        $definition = "";
        $primary_keys = [];

        foreach ($columns as $name => $column) {
            $type = $this->get_sql_data_type($column['type']);
            $default = isset($column['default']) ? " DEFAULT {$column['default']}" : '';
            $on_update = isset($column['on_update']) ? " ON UPDATE {$column['on_update']}" : '';
            $unique = isset($column['unique']) && $column['unique'] ? " UNIQUE" : '';

            $definition .= "`{$name}` $type NOT NULL$default$on_update$unique";

            if (!empty($column['auto'])) {
                $definition .= " AUTO_INCREMENT, ";
            } else {
                $definition .= ", ";
            }

            if (!empty($column['primary'])) {
                $primary_keys[] = $name;
            }
        }

        $primary_keys_str = implode(',', array_map(function ($key) {
            return "`{$key}`";
        }, $primary_keys));

        $definition .= "PRIMARY KEY ($primary_keys_str)";
        return rtrim($definition, ', ');
    }

    private function installtable() {
        global $wpdb;
        $table_name = $this->tablename();
        $collate = '';

        if ($wpdb->has_cap('collation')) {
            $collate = $wpdb->get_charset_collate();
        }

        $columns_info = $this->get_columns_info();
        $columns = $columns_info;

        $sql = "CREATE TABLE IF NOT EXISTS `{$table_name}`(";
        $sql .= $this->get_columns_definition($columns);
        $sql .= ") $collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }

    private function get_sql_data_type($type) {
        return $this->get_type_mappings( $type )['sql'];
    }

    private function get_prepare_data_type( $type ){
        return $this->get_type_mappings($type)['prepare'];
    }

    private function get_type_mappings($type) {
        $mappings = [
            'int' => ['sql' => 'bigint(20)', 'prepare' => '%d'],
            'float' => ['sql' => 'float', 'prepare' => '%f'],
            'string' => ['sql' => 'varchar(255)', 'prepare' => '%s'],
            'data'  => ['sql'=> 'MEDIUMTEXT', 'prepare'=> '%s'],
            'datetime' => ['sql' => 'datetime', 'prepare' => '%s'],
            'timestamp' => ['sql' => 'timestamp', 'prepare' => '%s']
        ];

        return $mappings[$type];
    }



    function insert($data) {
        global $wpdb;
        $table_name = $this->tablename();

        $allowed_columns = $this->get_columns_info();
        $insert_data = array();
        $format = array();

        // Check each key in the provided data. If it's an allowed column, add it to the insert data
        foreach ($data as $key => $value) {
            if (array_key_exists($key, $allowed_columns)) {
                // Check if the value needs to be serialized
                if ( is_array($value) ) {
                    $value = serialize($value);
                }
                $insert_data[$key] = $value;
                $type = $this->get_prepare_data_type( $allowed_columns[$key]['type'] );
                $format[] = $type;  // Get the appropriate format
            }
        }

        // Perform the insert operation
        $result = $wpdb->insert(
            $table_name,
            $insert_data,
            $format
        );

        // If there was an error, throw an exception. Otherwise, return the ID of the new entry
        if ($result === false) {
            throw new \Exception($wpdb->last_error);
        }

        return $wpdb->insert_id;
    }

    function delete($args = []) {
        global $wpdb;
        $table_name = $this->tablename();

        $prepped_clauses = $this->prepare_where_clauses( $args );

        if( empty( $prepped_clauses[1] )){
            throw new \Exception('No values.');
        }

        $sql = $wpdb->prepare("DELETE FROM `{$table_name}` WHERE {$prepped_clauses[0]}", $prepped_clauses[1]);

        return $wpdb->query($sql);
    }

    function update($data, $where) {
        global $wpdb;
        $table_name = $this->tablename();

        $allowed_columns = $this->get_columns_info();
        $data_to_update = array();
        $where_data = array();
        $data_format = array();
        $where_format = array();

        // Check each key in the provided data. If it's an allowed column, add it to the data_to_update
        foreach ($data as $key => $value) {
            if (array_key_exists($key, $allowed_columns)) {
                // If the column type is 'array', serialize the value
                if ( is_array($value) ) {
                    $value = serialize($value);
                }
                $data_to_update[$key] = $value;
                $type = $this->get_prepare_data_type( $allowed_columns[$key]['type'] );
                $data_format[] = $type;  // Get the appropriate format
            }
        }

        // Check each key in the provided where clause. If it's an allowed column, add it to the where_data
        foreach ($where as $key => $value) {
            if (array_key_exists($key, $allowed_columns)) {
                $where_data[$key] = $value;
                $type = $this->get_prepare_data_type( $allowed_columns[$key]['type'] );
                $where_format[] = $type;  // Get the appropriate format
            }
        }

        // Perform the update operation
        $result = $wpdb->update(
            $table_name,
            $data_to_update,
            $where_data,
            $data_format,
            $where_format
        );

        // If there was an error, throw an exception. Otherwise, return the number of rows affected
        if ($result === false) {
            throw new \Exception($wpdb->last_error);
        }

        return $result;
    }
    function count($args = []) {
        global $wpdb;
        $table_name = $this->tablename();

        $prepped_clauses = $this->prepare_where_clauses( $args );

        $sql = $wpdb->prepare("SELECT COUNT(*) FROM `{$table_name}` WHERE {$prepped_clauses[0]}",  $prepped_clauses[1]);

        return intval($wpdb->get_var($sql));
    }


    function get_allowed_compares() {
        return ['=', '<', '>', '<=', '>=', '<>', 'LIKE'];
    }


    function prepare_where_clauses( $args ){
        $whereClauseParts = ["1=1"];
        $prepareArgs = [];
        $allowed_columns = $this->get_columns_info();
        $allowed_compares = $this->get_allowed_compares();

        foreach ($args as $column => $condition) {
            if (isset($allowed_columns[$column])) {
                $type = $this->get_prepare_data_type($allowed_columns[$column]['type']);

                // Handle the case where condition is an array
                if (is_array($condition)) {
                    $value = $condition['value'] ?? null; // Use null as a default if 'value' is not set
                    $compare = $condition['compare'] ?? '='; // Use '=' as a default if 'compare' is not set
                    if (!in_array($compare, $allowed_compares)) {
                        $compare = '=';
                    }
                } else {
                    // If the condition is not an array, treat it as a value
                    $value = $condition;
                    $compare = '=';
                }

                $whereClauseParts[] = "`{$column}` {$compare} {$type}";
                $prepareArgs[] = $value;
            }
        }
        $whereClause = implode(" AND ", $whereClauseParts);
        return [ $whereClause, $prepareArgs];

    }

    function query($args = [], $limit = 0, $offset = 0) {
        global $wpdb;
        $table_name = $this->tablename();
        $limit = absint($limit);

        if ($limit == 0) {
            $limit = $this->TABLE_LIMIT;
        }

        $prepped_clauses = $this->prepare_where_clauses( $args );


        $sql = $wpdb->prepare("SELECT * FROM `{$table_name}` WHERE {$prepped_clauses[0]} LIMIT {$limit} OFFSET {$offset}", $prepped_clauses[1]);

        $results = $wpdb->get_results($sql, ARRAY_A);

        foreach ($results as &$row) {
            foreach ($row as $column => &$value) {
                if ($this->is_serialized($value)) {
                    $value = unserialize($value);
                }
            }
        }

        return $results;
    }

// Helper method to check if a string is serialized data
    function is_serialized($str) {
        return ($str == serialize(false) || @unserialize($str) !== false);
    }

    function get_row($args = []) {
        $results = $this->query($args, 1);
        return !empty($results) ? $results[0] : null;
    }


    function get_column($column_name, $args = []) {
        $row = $this->get_row($args);
        return $row[$column_name] ?? null;
    }

    function get_columns_info(){
        return $this->columns_info;
    }


    private function tablename(){
        global $wpdb;
        return $wpdb->prefix .  $this->get_name();
    }


    function get_name(){
        return $this->name;
    }

    function get_tablename(){
        return $this->tablename();
    }


}