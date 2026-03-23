<?php
require_once __DIR__ . '/../includes/runtime_config.php';
class clsConexion {
    private $con;

    function __construct() {
        try {
            $dbCfg = runtime_db_config();
            $host = $dbCfg['host'];
            $db_name = $dbCfg['name'];
            $user = $dbCfg['user'];
            $pass = $dbCfg['pass'];

            // Cadena de conexión
            $this->con = new mysqli($host, $user, $pass, $db_name);
            
            // Verificar conexión
            if ($this->con->connect_error) {
                throw new Exception("ERROR AL CONECTAR LA BASE DE DATOS: " . $this->con->connect_error);
            }

            $this->con->set_charset("utf8");
        } catch (Exception $ex) {
            // Manejo de excepciones
            echo $ex->getMessage();
        }
    }

    function consultar($sql) {
        $res = $this->con->query($sql);
        $data = [];

        if ($res) {
            while ($fila = $res->fetch_assoc()) {
                $data[] = $fila;
            }
            $res->free();
        } else {
            // Manejo de errores en caso de fallo en la consulta
            echo "Error en consulta: " . $this->con->error;
        }

        return $data;
    }

public function ejecutar($sql){
    // Detectar el recurso mysqli aunque no se llame $cn
    $mysqli = null;

    foreach (['cn','con','conn','conexion','link','db','mysqli'] as $prop) {
        if (property_exists($this, $prop) && $this->$prop instanceof mysqli) {
            $mysqli = $this->$prop;
            break;
        }
    }

    if (!$mysqli) {
        // si tu clase guarda el mysqli en una variable local, aquí fallará igual.
        die("Error: no se encontró conexión mysqli activa en clsConexion.");
    }

    $r = mysqli_query($mysqli, $sql);
    if(!$r){
        die("Error en consulta: " . mysqli_error($mysqli) . " | SQL: " . $sql);
    }
    return $r;
}



    public function real_escape_string($string) {
        return $this->con->real_escape_string($string);
    }

    public function prepare($sql) {
        if (method_exists($this->con, 'prepare')) {
            return $this->con->prepare($sql);
        } else {
            throw new Exception('Método prepare no soportado por la conexión.');
        }
    }
    
        public function getError() {
        return $this->con->error;
    }


    function __destruct() {
        $this->con->close();
    }
}
?>
