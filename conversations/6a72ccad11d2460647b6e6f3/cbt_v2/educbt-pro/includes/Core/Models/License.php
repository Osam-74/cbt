<?php

namespace EduCBTPro\Core\Models;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class License {
    public ?int $id = null;
    public int $school_id = 0;
    public string $license_key = '';
    public string $license_type = '';
    public string $status = 'active';
    public string $issued_at = '';
    public string $expires_at = '';
    public string $created_at = '';
    public string $updated_at = '';
}
