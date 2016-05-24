<?php
/**
 * CalCrypt.php
 * User: PMinkler
 * Date: 4/28/2016
 * Time: 11:22 AM
 */

namespace OC\Encryption;

use OC\OCS\Exception;
use OCP\DB\QueryBuilder\IQueryBuilder;


/**
 * Class CssCrypt
 */
class CalCrypt
{
    /**
     * @var array The fields we want to encrypt
     */
    private $encrypted_fields = [
        'calendardata',
        'calendarData',
        'password'
    ];
    /**
     * @var string The key used to encrypt the data
     */
    private $key;
    /**
     * @var IQueryBuilder The Query object
     */
    private $data;

    /**
     * CssCrypt constructor.
     *
     * @param IQueryBuilder $data The Query object
     *
     * @throws \UnexpectedValueException
     */
    public function __construct(IQueryBuilder $data)
    {
        $this->data = $data;
        $this->key = \OC::$server->getConfig()->getSystemValue('encrypt_key');

        if ($this->key === '') {
            $logger = \OC::$server->getLogger();
            $logger->warning("Error occurred while writing user's password");
            $logger->logException(
                new \UnexpectedValueException('Value must exist for encrypt_key in the config to use encryption')
            );
        }
    }

    /**
     * Decrypts data coming from the database
     *
     * @return mixed
     */
    public function decryptData()
    {
        $columns = [];
        foreach ($this->data->getQueryParts()['select'] as $column) {
            // Unquoting column names
            $column = str_replace("`", "", $column);
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
     * @param array $values The values to encrypt
     *
     * @return mixed
     */
    public function insertEncrypted(array $values)
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

    /**
     * Encrypts data being updated in the database
     *
     * @param array $values The values to encrypt
     *
     * @return mixed
     */
    public function updateEncrypted(array $values)
    {
        foreach ($values as $column => $value) {
            if (in_array($column, $this->encrypted_fields)) {
                $this->data->set($column, $this->data->createFunction("AES_ENCRYPT('$value', '$this->key')"));
            } else {
                $this->data->set($column, $this->data->createNamedParameter($value));
            }
        }
    }
}
