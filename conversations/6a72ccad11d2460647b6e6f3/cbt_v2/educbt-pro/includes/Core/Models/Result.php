<?php

namespace EduCBTPro\Core\Models;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Result {
    public ?int $id = null;
    public int $school_id = 0;
    public int $student_id = 0;
    public string $term = '';
    public string $session_year = '';
    public string $subject = '';
    public float $score = 0.0;
    public float $grade = 0.0;
    public string $remark = '';
    public string $status = 'draft';
    public string $created_at = '';
    public string $updated_at = '';
}
