<?php
/**
 * User: PMinkler
 * Date: 4/28/2016
 * Time: 11:16 AM
 */

namespace OC\Encryption;


/**
 * Interface CalCrypt
 * @package OC\Encryption
 */
interface CalCrypt
{
    /**
     * Decrypts data coming from the database
     * @return mixed
     */
    public function decryptData();

    /**
     * Encrypts data being saved in the database
     *
     * @param array $values
     *
     * @return mixed
     */
    public function encryptData(array $values);
}
