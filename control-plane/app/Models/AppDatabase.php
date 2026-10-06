<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Crypt;

/** Baza danych aplikacji na serwerze MySQL/MariaDB — z własnym użytkownikiem. */
class AppDatabase extends Model
{
    protected $fillable = ['app_server_id', 'database_host_id', 'database', 'username', 'password', 'remote'];

    protected $hidden = ['password'];

    /** @return BelongsTo<AppServer, $this> */
    public function app(): BelongsTo
    {
        return $this->belongsTo(AppServer::class, 'app_server_id');
    }

    /** @return BelongsTo<DatabaseHost, $this> */
    public function host(): BelongsTo
    {
        return $this->belongsTo(DatabaseHost::class, 'database_host_id');
    }

    public function setSecret(string $password): void
    {
        $this->password = Crypt::encryptString($password);
    }

    public function secret(): string
    {
        return Crypt::decryptString($this->password);
    }

    /** Czy panel może połączyć się kontem klienta (przeglądarka bazy). */
    public function browsable(): bool
    {
        return $this->remote === '%';
    }

    public function jdbcUrl(): string
    {
        return 'jdbc:mysql://'.$this->host->clientHost().':'.$this->host->port.'/'.$this->database;
    }
}
