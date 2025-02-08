<?php

namespace App\Services;

use RouterOS\Client;
use RouterOS\Query;

class MikroTikService
{
    protected $client;

    public function __construct($host, $user, $pass)
    {
        $this->client = new Client([
            'host' => $host,
            'user' => $user,
            'pass' => $pass,
        ]);
    }

    /**
     * Disconnect user dari MikroTik
     */
    public function disconnectUser($username)
    {
        $query = (new Query('/ip/hotspot/active/remove'))
            ->equal('name', $username);

        return $this->client->query($query)->read();
    }

    /**
     * Tambah atau update aturan di MikroTik
     */
    public function updateRadReply($username, $attribute, $op, $value)
    {
        // Contoh perintah untuk update di MikroTik, sesuaikan dengan kebutuhan
        $query = (new Query('/radius/reply/add'))
            ->equal('username', $username)
            ->equal('attribute', $attribute)
            ->equal('value', $value)
            ->equal('op', $op);

        return $this->client->query($query)->read();
    }
}
