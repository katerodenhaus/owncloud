<?php
/**
 * CalCrypt.php
 * User: PMinkler
 * Date: 4/28/2016
 * Time: 11:22 AM
 */

namespace OC\Encryption;

use OCP\DB\QueryBuilder\IQueryBuilder;


/**
 * Class CssCrypt
 * @package OC\Encryption
 */
class CalCrypt
{
    private $key;
    private $data;
    private $encrypted_fields = [
        'calendardata'
    ];

    /**
     * CssCrypt constructor.
     *
     * @param $data
     */
    public function __construct(IQueryBuilder $data)
    {
        $this->data = $data;
        $this->key  = \OC::$server->getConfig()->getSystemValue('encrypt_key');
    }

    /**
     * Decrypts data coming from the database
     * @return mixed
     */
    public function decryptData()
    {
        $columns = [];
        foreach ($this->data->getQueryParts()['select'] as $column) {
            $column = str_replace("`", "", $column); // Unquoting column names
            if (in_array($column, $this->encrypted_fields)) {
                $column = $this->data->createFunction("AES_DECRYPT($column, '$this->key') AS $column");
            }

            $columns[] = $column;
        }

        return $this->data->select($columns);
    }

    /**
     * Encrypts data being saved in the database
     *
     * @param array $values
     *
     * @return mixed
     */
    public function encryptData(array $values)
    {
        $encrypted_values = [];
        foreach ($values as $column => $value) {
            if (in_array($column, $this->encrypted_fields)) {
                $encrypted_values[$column] = $this->data->createFunction("AES_ENCRYPT('$value', '$this->key')");
            } else {
                $encrypted_values[$column] = $this->data->createNamedParameter($value);
            }
        }

        return $this->data->values($encrypted_values);
    }
}
