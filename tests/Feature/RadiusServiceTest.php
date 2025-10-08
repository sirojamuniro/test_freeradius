<?php

namespace Tests\Feature;

use App\Services\RadiusService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class RadiusServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', ':memory:');

        DB::purge('sqlite');
        DB::setDefaultConnection('sqlite');
        DB::reconnect('sqlite');

        Schema::dropIfExists('radcheck');
        Schema::dropIfExists('radreply');
        Schema::dropIfExists('nas');

        Schema::create('radcheck', function (Blueprint $table) {
            $table->id();
            $table->string('username');
            $table->string('attribute');
            $table->string('op', 2)->default(':=');
            $table->string('value');
        });

        Schema::create('radreply', function (Blueprint $table) {
            $table->id();
            $table->string('username');
            $table->string('attribute');
            $table->string('op', 2)->default(':=');
            $table->string('value');
        });

        Schema::create('nas', function (Blueprint $table) {
            $table->id();
            $table->string('nasname')->unique();
            $table->string('shortname')->nullable();
            $table->integer('ports')->nullable();
            $table->string('secret');
            $table->string('type')->nullable();
            $table->string('server')->nullable();
        });
    }

    public function test_add_user_upserts_radreply_attributes_without_duplicates(): void
    {
        $service = app(RadiusService::class);

        $service->addUser('alice', 'secret123', 'mikrotik', '192.168.1.1', 3799, 'coasecret', [
            'max_download' => '20M',
            'max_upload' => '5M',
            'min_download' => '3M',
            'min_upload' => '2M',
        ]);

        $this->assertSame(1, DB::table('radreply')->where('username', 'alice')->count());
        $this->assertSame('20M/5M', DB::table('radreply')->where('username', 'alice')->where('attribute', 'Mikrotik-Rate-Limit')->value('value'));
        $this->assertSame(0, DB::table('radreply')->where('username', 'alice')->where('attribute', 'Vendor-Type')->count());
        $this->assertSame(0, DB::table('radreply')->where('username', 'alice')->where('attribute', 'Mikrotik-Total-Limit')->count());
        $this->assertSame(0, DB::table('radreply')->where('username', 'alice')->where('attribute', 'Session-Timeout')->count());

        $service->addUser('alice', 'newpass456', 'mikrotik', '192.168.1.1', 3799, 'coasecret', [
            'max_download' => '30M',
            'max_upload' => '10M',
            'min_download' => '4M',
            'min_upload' => '1M',
        ]);

        $this->assertSame(1, DB::table('radreply')->where('username', 'alice')->count());
        $this->assertSame('30M/10M', DB::table('radreply')->where('username', 'alice')->where('attribute', 'Mikrotik-Rate-Limit')->value('value'));
        $this->assertSame(0, DB::table('radreply')->where('username', 'alice')->where('attribute', 'Vendor-Type')->count());
        $this->assertSame(0, DB::table('radreply')->where('username', 'alice')->where('attribute', 'Mikrotik-Total-Limit')->count());
        $this->assertSame(0, DB::table('radreply')->where('username', 'alice')->where('attribute', 'Session-Timeout')->count());

        $this->assertSame(1, DB::table('radcheck')->where('username', 'alice')->where('attribute', 'Cleartext-Password')->count());
        $this->assertSame('newpass456', DB::table('radcheck')->where('username', 'alice')->where('attribute', 'Cleartext-Password')->value('value'));

        $this->assertSame(1, DB::table('nas')->count());
    }
}
