<?php

namespace EduCBTPro\Core\Models;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Transcript {
    public ?int $id = null;
    public int $school_id = 0;
    public int $student_id = 0;
    public string $terms = '';
    public string $sessions = '';
    public string $summary = '';
    public string $created_at = '';
    public string $updated_at = '';
}
