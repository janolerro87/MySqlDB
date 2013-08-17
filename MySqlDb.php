<?php

class MySqlDb {

	/*
	 * Objeto de la conexion a la base de datos
	 */
	private $_mysql = NULL;

	/*
	 * Instancia unica de la clase
	 */
	private static $_instance = NULL;

	/*
	 * Nombre de la base de datos a conectar
	 */
	private $_db = NULL;

	/*
	 * Direccion del servidor de base de datos (MySql)
	 */
	private $_host = NULL;

	/*
	 * Usuario del servidor de base de datos (MySql)
	 */
	private $_user = NULL;

	/*
	 * Contrasena del servidor de base de datos (MySql)
	 */
	private $_pass = NULL;

	/*
	 * Cadena para almacenar la porcion de consulta sql con una posible inyeccion sql
	 */
	private $_bad_code = NULL;

	/*
	 * Cadena para almacenar la consulta sql
	 */
	public $sql = NULL;

	/*
	 * Identificador del ultimo registro insertado
	 */
	public $last_inserted = NULL;

	/*
	 * Numero de registros arrojados en una consulta sql
	 */
	public $num_rows = NULL;

	/*
	 * Porcion de consulta sql para agrupar registros
	 */
	public $group_by = NULL;

	/*
	 * Porcion de consulta sql para ordenar registros
	 */
	public $order_by = NULL;

	/*
	 * Porcion de consulta sql para limitar el numero de registros devueltos
	 */
	public $limit = NULL;

	/*
	 * Cadena para almacenar el posible error que exista dentro de una consulta sql
	 */
	public $error = NULL;


	/**
	 * Funcion singleton para evitar multiples conexiones a la base de datos
	 * @param  string $host     Direccion del servidor de base de datos (MySql)
	 * @param  string $username Usuario del servidor de base de datos (MySql)
	 * @param  string $password Contrasena del usuario de la base de datos
	 * @param  string $db       Nombre de la base de datos a conectar
	 * @return $_instance       Instancia unica de la clase
	 */
	public static function connect($host, $username, $password, $db) {
		if(!self::$_instance) {
			self::$_instance = new self($host, $username, $password, $db);
		}
		return self::$_instance;
	}
	/**
	 * Constructor de la clase para realizar la conexion a la base de datos
	 * @param  string $host     Direccion del hostname del servidor MySql
	 * @param  string $username Usuario de la base de datos
	 * @param  string $password Contraseña del usuario de la base de datos
	 * @param  string $db       Nombre de la base de datos a conectar
	 */
	private function __construct($host, $username, $password, $db) {
		if($this->_mysql === NULL) {
			try {
				$this->_mysql = new PDO("mysql:host=$host;dbname=$db",$username, $password);
				$this->_db = $db;
				$this->_host = $host;
				$this->_user = $username;
				$this->_pass = $password;
			} catch (PDOException $e) {
				$this->error = $e->getMessage();
			}
		}
	}
	/**
	 * Destructor de la clase. Elimina la conexion a la base de datos.
	 */
	public function __destruct() {
		$this->mysql = NULL;
	}
	/**
	 * Metodo magico __toString que se ejecuta en caso de que se imprima el objeto
	 * @return string Ejecuta el metodo debug() de la clase
	 */
	public function __toString() {
		return $this->debug();
	}
	/**
	 * Reacomoda los indices asociativos para su futura utilizacion en las consultas sql
	 * @param  array $bindings Arreglo asociativo con los indices normales
	 * @return array           Arreglo asociativo con los indices modificados
	 */
	private function _parseBindings($bindings) {
		$result = array();

		foreach ($bindings as $key => $val) {
			$result[":".$key] = $val;
		}
		return $result;
	}
	/**
	 * Prepara la consulta para su futura ejecucion
	 * @return statement Sentencia preparada para su futura ejecucion
	 */
	private function _prepareQuery() {
		if(isset($this->_mysql)) {
			if (!$stmt = $this->_mysql->prepare($this->sql)) {
				trigger_error("Problem preparing query", E_USER_ERROR);
			}
			return $stmt;
		}
	}
	/**
	 * Metodo para ejecutar la sentencia preparada (la consulta)
	 * @param  array   $args       Arreglo con los datos necesarios para la consulta.
	 * @param  boolean $returnData Bandera para indicar si la consulta debe regresar datos o no
	 * @return array|boolean              Arreglo con los datos devueltos de la consulta.
	 *                                    Si las consulta fue INSERT,UPDATE o DELETE regresara true.
	 *                                    En caso contrario de que la consulta no regrese datos regresará false.
	 */
	private function _query($args = array(), $returnData = TRUE) {
		if(isset($this->_mysql)) {

			// $this->sql = filter_var($query, FILTER_SANITIZE_STRING);
			$stmt = $this->_prepareQuery();
			$stmt->execute($args);
			$this->num_rows = $stmt->rowCount();
			$this->last_inserted = $this->_mysql->lastInsertId();

			if($returnData) {
				if($this->error === NULL) {

					$results = $stmt->fetchAll(PDO::FETCH_ASSOC);
					return $results ? $results : false;
				} else {
					return false;
				}
			} else {
				return true;
			}
		}
	}
	/**
	 * Metodo sencillo para anilizar y 'sanitizar' los datos de la consulta para evitar inyecciones SQL.
	 * @param  string|integer $value Datos para ser analizado
	 * @return string|integer        Dato analizado
	 */
	private function _sanitize($value) {
		$type = gettype($value);
		if($type === 'string') {
			$value = strip_tags($value);
			$value = filter_var($value, FILTER_SANITIZE_MAGIC_QUOTES);
			// $value = filter_var($value, FILTER_SANITIZE_STRING);
			$len = strlen($value);
			$bad = array();

			$inj = stripos($value, "'");
			if($inj) {
				$this->_bad_code = substr($value, $inj, $len);
				if($value[$inj-1] === "\\") {
					$value = substr($value, 0, $inj-2);
				} else {
					$value = substr($value, 0, $inj);
				}
			}
			$value = "'$value'";
		} else {
			$value = (int) $value;
		}
		return $value;
	}
	/**
	 * Metodo para ejecutar cualquier consulta sql
	 * @param  string $query Consulta sql
	 * @param  array  $args  Arreglo que contiene los datos para ejecutar la consulta.
	 *                       Si la consulta modifica datos de la base de datos (INSERT, UPDATE, DELETE), es necesario escribir este parametro.
	 *                       Si la consulta es para extraer datos de la base de datos (SELECT), no es necesario escribir el paramentro
	 * @return array|boolean        Ejecuta el metodo _query() de la clase
	 */
	public function run($query, $args = array()) {
		$this->sql = $query;

		return $this->_query($args);
	}
	/**
	 * Metodo para insertar los datos en la base de datos
	 * @param string $tableName     Nombre de la tabla en donde se insertaran los datos
	 * @param array $insertData	    Arreglo asociativo con los datos a insertar en la base de datos
	 * @return boolean true|false   Si los datos fueron introducidos a la base de datos, regresa true
	 *                              Si hubo un error al introducir los datos, regresa false
	 */
	public function insert($tableName, $insertData) {
		$this->sql = "INSERT INTO $tableName ";

		$keys = array_keys($insertData);
		$values = array_values($insertData);

		$this->sql .= '(' . implode($keys, ', ') . ')';
		$this->sql .= ' VALUES ';
		$this->sql .= '(:' . implode($keys, ', :') . ')';
		
		$insertData = $this->_parseBindings($insertData);
		$this->_query($insertData, FALSE);

		if(($this->num_rows)!== 0) {
			return true;
		} else {
			return false;
		}
	}
	/**
	 * Metodo para actualizar los datos en la base de datos
	 * @param string $tableName    Nombre de la dabla en donde se actualizaran los datos
	 * @param array $whereData     Arreglo asociativo con los datos necesarios para la clausula WHERE de la consulta sql
	 * @param array $newData       Arreglo asociativo con los datos nuevos que se van a actualizar.
	 * @return boolean true|false  Si los datos fueron actualizados a la base de datos, regresa true
	 *                             Si hubo un error al actualizar los datos, regresa false
	 */
	public function update($tableName, $whereData, $newData) {
		$this->sql = "UPDATE $tableName SET";

		$i = 1;
		foreach ($newData as $prop => $value) {
			// prepares the rest of the SQL Query
			if ( $i === count($newData) ) {
				$this->sql .= " $prop = :$prop";
			} else {
				$this->sql .= " $prop = :$prop,";
			}
			$i++;
		}
		$i = 1;
		foreach ($whereData as $prop => $value) {
			// prepares the rest of the SQL Query
			if(gettype($value) == "string") {
				$value = "'$value'";
			}
			if ( $i === 1 ) {
				$this->sql .= " WHERE $prop = $value";
			} else {
				$this->sql .= " AND $prop = $value";
			}
			$i++;
		}
		$newData = $this->_parseBindings($newData);
		$this->_query($newData, FALSE);

		if(($this->num_rows)!== 0) {
			return true;
		} else {
			return false;
		}
	}
	/**
	 * Metodo para eliminar los datos en la base de datos
	 * @param string $tableName    Nombre de la dabla en donde se eliminaran los datos
	 * @param array $whereData     Arreglo asociativo con los datos necesarios para la clausula WHERE de la consulta sql
	 * @return boolean true|false  Si los datos fueron eliminados a la base de datos, regresa true
	 *                             Si hubo un error al eliminar los datos, regresa false
	 */
	public function delete($tableName, $whereData) {
		$this->sql = "DELETE FROM $tableName";

		$i = 1;
		foreach ($whereData as $prop => $value) {
			if(gettype($value) == "string") {
				$value = "'$value'";
			}
			// prepares the rest of the SQL Query
			if ( $i === 1 ) {
				$this->sql .= " WHERE $prop = $value";
			} else {
				$this->sql .= " AND $prop = $value";
			}
			$i++;
		}
		// $newData = $this->_parseBindings($newData);
		$this->_query();

		if(($this->num_rows)!== 0) {
			return true;
		} else {
			return false;
		}
	}
	/**
	 * Metodo para obtener todos los datos de la base de datos
	 * @param string $tableName     Nombre de la tabla donde se buscaran los datos
	 * @param array $whereData      Arreglo asociativo con los datos necesarios para la clausula WHERE de la consulta sql
	 * @param array|string $select  Arreglo asociativo o cadena con los datos necesarios para la clausula SELECT de la consulta sql
	 * @return array|boolean $data  Si existen datos en la base de datos que coincidan con el parametro $select el metodo devolvera un arreglo
	 *                              Si no existen datos en la base de datos u ocurra un error, el metodo devolvera false
	 */
	public function get_all($tableName, $whereData = array(), $select = NULL) {
		$this->sql = "SELECT";
		$return = "";

		if(!isset($select)) {
			$this->sql .= " *";
		}
		elseif(is_array($select)) {
			$this->sql .= " ".implode($select, ', ');
		}
		else {
			$this->sql .= " $select";
		}
		
		$this->sql .= " FROM ".$this->_db.".".$tableName;

		if(count($whereData)!== 0) {
			$this->sql .= " WHERE";

			$i = 1;
			foreach ($whereData as $prop => $val) {
				$value = $this->_sanitize($val);
				// prepares the rest of the SQL Query
				if ( $i === 1 ) {
					$this->sql .= " $prop = $value";
				} else {
					$this->sql .= " AND $prop = $value";
				}
				$i++;
			}
		}

		if($this->order_by != NULL) {
			$this->sql .= " ORDER BY ". $this->order_by;
		}
		if($this->group_by != NULL) {
			$this->sql .= " GROUP BY ". $this->group_by;
		}
		if($this->limit != NULL) {
			$this->sql .= " LIMIT ". $this->limit;
		}
		$table_exists = $this->table_exists($tableName);
		if($table_exists) {
			$data = $this->_query();
			$this->order_by = NULL;
			$this->group_by = NULL;
			$this->limit = NULL;
			return $data ? $data : false;
		} else {
			$this->error = "Base table or view not found";
			return false;
		}
	}
	/**
	 * Metodo para obtener un registro de datos de la base de datos
	 * @param string $tableName     Nombre de la tabla donde se buscaran los datos
	 * @param array $whereData      Arreglo asociativo con los datos necesarios para la clausula WHERE de la consulta sql
	 * @param array|string $select  Arreglo asociativo o cadena con los datos necesarios para la clausula SELECT de la consulta sql
	 * @return array|boolean $data  Si existen datos en la base de datos que coincidan con el parametro $select el metodo devolvera un arreglo
	 *                              Si no existen datos en la base de datos u ocurra un error, el metodo devolvera false
	 */
	public function get_row($tableName, $whereData, $select = NULL) {
		$data = $this->get_all($tableName, $whereData, $select)[0];
		if($data) {
			return $data;
		} else {
			return false;
		}
	}
	/**
	 * Metodo para obtener un campo en especifico de la base de datos
	 * @param string $tableName      Nombre de la tabla donde se buscaran los datos
	 * @param array $whereData       Arreglo asociativo con los datos necesarios para la clausula WHERE de la consulta sql
	 * @param array|string $select   Arreglo asociativo o cadena con los datos necesarios para la clausula SELECT de la consulta sql
	 * @return string|boolean $data  Si existen datos en la base de datos que coincidan con el parametro $select, el metodo devolvera una cadena
	 *                               Si no existen datos en la base de datos, el metodo devolvera false
	 */
	public function get_var($tableName, $whereData, $select) {
		$data = $this->get_all($tableName, $whereData, $select)[0][$select];
		if($data) {
			return $data;
		} else {
			return false;
		}
	}
	/**
	 * Metodo para obtener el numero de registros que coincidan con los datos especificados (con ayuda de la funcion de sql COUNT() )
	 * @param string $tableName      Nombre de la tabla donde se buscaran los datos
	 * @param array $whereData       Arreglo asociativo con los datos necesarios para la clausula WHERE de la consulta sql
	 * @param array|string $select   Arreglo asociativo o cadena con los datos necesarios para la clausula SELECT de la consulta sql
	 * @param boolean $distinct      Activa la bandera DISTINCT para expecificar valores distintos dentro de la consulta sql
	 * @return string|boolean $data  Si existen datos en la base de datos que coincidan con el parametro $select, el metodo devolvera el numero de la cadena
	 *                               Si no existen datos en la base de datos, el metodo devolvera false
	 */
	public function get_count($tableName, $whereData = NULL, $selectData = NULL, $distinct = FALSE) {
		$select = "COUNT(";
		if( $selectData !== NULL) {
			if($distinct) {
				$select .= "DISTINCT ";
			}
			$select .= "$selectData)";
		} else {
			$select .= "*)";
		}
		return $this->get_var($tableName, $whereData, $select)[0][$select];
	}
	/**
	 * Metodo para verificar si existe o no un registro en la base de datos
	 * @param string $tableName   Nombre de la tabla donde se buscaran los datos
	 * @param array $whereData    Arreglo asociativo con los datos necesarios para la clausula WHERE de la consulta sql
	 * @return boolean $data      Si existen datos en la base de datos que coincidan con el parametro $select, el metodo devolvera true
	 *                            Si no existen datos en la base de datos, el metodo devolvera false
	 */
	public function exists($tableName, $whereData) {
		$exists = $this->get_row($tableName, $whereData);
		if($exists) {
			return true;
		} else {
			return false;
		}
		
	}
	/**
	 * Metodo para verificar si existe o no una tabla en la base de datos
	 * - Dentro de este metodo, preferi utilizar otra conexion independiente a la base de datos (cuando es invocado por otros metodos) 
	 *   para no afectar las demas propiedades de la clase, como por ejemplo: $this->error y $this->sql
	 * 
	 * @param string $tableName   Nombre de la tabla donde se buscaran los datos
	 * @return boolean $data      Si existen datos en la base de datos que coincidan con el parametro $select, el metodo devolvera true
	 *                            Si no existen datos en la base de datos, el metodo devolvera false
	 */
	public function table_exists($tableName) {
		$dbname = $this->_db;
		$host = $this->_host;
		$user = $this->_user;
		$pass = $this->_pass;
		$tmpCon = new PDO("mysql:host=$host;dbname=$dbname",$user, $pass);
		$stmt = $tmpCon->prepare('SHOW TABLES LIKE :table');
		$stmt->execute(array(":table" => $tableName));
		$table = $stmt->rowCount();
		if($table !== 0) {
			return true;
		} else {
			$this->error = "Base table or view not found";
			return false;
		}
	}
	/**
	 * Metodo para imprimir una simple depuracion la consulta sql. En caso de que ocurra un error sera mostrado
	 * @return string $return  Mensaje desplegado
	 */
	public function debug() {

		if($this->error) {
			$return = "Error: ". $this->error;
			$return.= "\nLast Query: ". $this->sql;
		} else {
			$return = "No error";
			$return.= "\nLast Query: ". $this->sql;
		}

		return $return;
	}
}