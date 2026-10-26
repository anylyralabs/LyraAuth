<?php

declare(strict_types=1);

namespace App\Model;

/**
 * @property int $id
 * @property int $client_id
 * @property string $scope_identifier
 */
class OauthClientScope extends Model
{
    protected ?string $table = 'oauth_client_scopes';
}
