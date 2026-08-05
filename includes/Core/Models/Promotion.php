<?php

namespace EduCBTPro\Core\Models;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Promotion {
    public ?int $id = null;
    public int $school_id = 0;
    public int $student_id = 0;
    public string $from_class = '';
    public string $to_class = '';
    public string $session_year = '';
    public string $status = 'pending';
    public string $created_at = '';
    public string $updated_at = '';
}
