<?php

namespace EduCBTPro\Core\Models;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Subject {
    public ?int $id = null;
    public int $school_id = 0;
    public string $subject_name = '';
    public string $subject_code = '';
    public string $subject_type = 'core';
    public string $created_at = '';
    public string $updated_at = '';
}
