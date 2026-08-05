<?php

namespace EduCBTPro\Core\Models;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class AuditLog {
    public ?int $id = null;
    public int $school_id = 0;
    public int $user_id = 0;
    public string $action = '';
    public string $object_type = '';
    public int $object_id = 0;
    public string $previous_value = '';
    public string $new_value = '';
    public string $ip_address = '';
    public string $device = '';
    public string $created_at = '';
}
