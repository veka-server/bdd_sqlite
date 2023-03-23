<?php

namespace VekaServer\BddSqlite;

use VekaServer\Interfaces\BddInterface;

class Bdd implements BddInterface {

    /**
     * @var \PDO|null $conn
     */
    protected $conn = null;

    /** @var \PDOStatement|false|null $stmt */
    protected $stmt = null;

    protected $charset;
    protected $path;

    public function __construct($param)
    {
        $this->path = $param['path'] ?? '';
        $this->charset = $param['charset'] ?? '';
    }

    /**
     * @param bool $check_conn
     *
     * @throws \Exception
     */
    public function connect(bool $check_conn = true)
    {
        if (!$this->conn instanceof \PDO) {
            $dsn = '';
            if ($this->path != '') {
                $dsn .= 'sqlite:' . $this->path . ';';
            }
            if ($this->charset != '') {
                $dsn .= 'options=\'--client_encoding=' . $this->charset . '\'' . ';';
            }
            $this->conn = new \PDO($dsn);
            $this->conn->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        }

        if ($check_conn) {
            if (!$this->conn instanceof \PDO) {
                throw new \Exception(__METHOD__ . '[' . $this->name . '].noConnection.');
            }
        }
    }

    /**
     * @throws \Exception
     */
    public function exec($sql, $param = [])
    {
        $stmt = $this->open($sql, $param);
        
        if(!($stmt instanceof \PDOStatement)){
            return $stmt;
        }

        return $this->fetchAll($stmt);
    }

    private function getTypeAndValueFromKey($param, $value): array
    {
        $tab = explode('-', $param);

        if (count($tab) == 1) {
            return [$tab[0], \PDO::PARAM_STR, $value];
        }

        if (is_null($value)) {
            return [$tab[0], \PDO::PARAM_NULL, null];
        }

        switch ($tab[0]) {
            case 'd': //date
            case 's': //string
                $type = \PDO::PARAM_STR;
                break;

            case 'b':

                if (!is_bool($value)) {
                    $value = strtolower(trim($value));
                }

                $type = \PDO::PARAM_BOOL;

                switch ($value) {
                    case true :
                        $value = true;
                        break;

                    case false :
                        $value = false;
                        break;

                    default :
                        $type = \PDO::PARAM_STR;
                        break;
                }
                break;

            case 'f': //float
                $type = \PDO::PARAM_STR;
                $value = (string)$value;
                break;

            case 'i': //integer

                if (PHP_INT_MAX <= $value) {
                    $type = \PDO::PARAM_INT;
                } else {
                    $type = \PDO::PARAM_STR;
                    $value = (string)$value;
                }

                break;

            default:
                $type = \PDO::PARAM_STR;
                break;
        }

        return [$tab[1], $type, $value];
    }

    private static function getReturnType(&$sql) {
        $sql=trim($sql);

        preg_match('/\b[a-zA-Z]+\b/i', $sql, $result);
        $type = $result[0];

        switch( $type ){
            case 'SELECT':
            case 'INSERT':
            case 'UPDATE':
                return $type;

            case 'REPLACE':
            case 'LOAD':
                return 'INSERT';

            case 'SET':
            case 'DELETE':
                return 'UPDATE';

            case 'SHOW':
            case 'EXPLAIN':
            case 'WITH':
            case 'DESCRIBE':
                return 'SELECT';

            case 'TRUNCATE':
            case 'DROP':
            case 'KILL':
            case 'LOCK':
            case 'UNLOCK':
            case 'CREATE':
            case 'OPTIMIZE':
            case 'ALTER':
            case 'VACUUM':
            case 'REINDEX':
            case 'ANALYZE':
            case 'IMPORT':
            case 'CALL':
                return 'NO_RETURN';

            default:
                return 'UNKNOW';
        }
    }

    /**
     * @param       $sql
     * @param array $param_sql
     *
     * @return array|bool|false|int|resource
     * @throws \Exception
     */
    public function open($sql, array $param_sql = array())
    {

        if (!$this->conn instanceof \PDO) {
            $this->connect();
        }

        /** modification de la requete pour integrer les parametre en array */
        foreach ($param_sql as $key => $value) {
            $tab = explode('-', $key);

            if (!in_array($tab[0], ['ai', 'as', 'ad', 'af'])) {
                continue;
            }

            if (!is_array($value)) {
                $value = [$value];
            }

            $type = str_replace('a', '', $tab[0]);

            // ajouter les nouveaux params
            $values = [];
            foreach ($value as $item) {
                $uniq_item = $tab[1] . '_' . uniqid();
                $values[] = ':' . $uniq_item;
                $param_sql[$type . '-' . $uniq_item] = $item;
            }

            // remplacer dans la requete sql
            $sql = str_replace(':' . $tab[1], implode(',', $values), $sql);
            unset($param_sql[$key]);
        }

        $stmt = $this->conn->prepare($sql);
        foreach ($param_sql as $key => $value) {
            list($key, $type, $value) = $this->getTypeAndValueFromKey($key, $value);

            try {
                $stmt->bindValue(':' . $key, $value, $type);
            } catch (\PDOException $e) {
                if ($e->getCode() != 'HY093') {
                    throw $e;
                }
            }

        }

        $return_type = self::getReturnType($sql);
        if ('UNKNOW' == $return_type) {
            throw new \Exception(__METHOD__ . '.return_type[UNKNOW].sql[' . $sql . ']');
        }

        if ($stmt->execute() === false) {
            throw new \Exception(__METHOD__ . '.error.num[' . $this->getErrorNum() . '].msg[' . $this->getErrorMsg() . '].return_type[' . $return_type . '].sql[' . $sql . ']');
        }

        if ('SELECT' == $return_type) {
            return $stmt;
        }

        if (in_array($return_type, ['UPDATE', 'DELETE'])) {
            return ['rowCount' => $stmt->rowCount()];
        }

        if ('INSERT' == $return_type) {
            return ['lastInsertId' => $this->conn->lastInsertId()];
        }

        return [];
    }

    /**
     * @throws \Exception
     */
    public function beginTransaction()
    {
        if (!$this->conn instanceof \PDO) {
            $this->connect();
        }
        return $this->conn->beginTransaction();
    }

    public function commit()
    {
        return $this->conn->commit();
    }

    public function rollback() {
        return $this->conn->rollback();
    }

    public function fetch($stmt)
    {
        return $stmt->fetch(\PDO::FETCH_ASSOC);
    }

    public function fetchAll($stmt)
    {
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    private function getErrorMsg() {
        return $this->conn->errorInfo()[2];
    }

    private function getErrorNum() {
        return ($this->conn->errorCode() != '000000') ? $this->conn->errorCode() : null;
    }

}
