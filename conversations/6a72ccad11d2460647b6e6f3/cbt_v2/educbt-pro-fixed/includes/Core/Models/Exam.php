<?php

namespace EduCBTPro\Core\Models;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Exam {
    public ?int $id = null;
    public int $school_id = 0;
    public string $title = '';
    public string $exam_type = '';
    public string $description = '';
    public string $start_time = '';
    public string $end_time = '';
    public int $duration_minutes = 0;
    public bool $is_published = false;
    public string $created_at = '';
    public string $updated_at = '';
}
