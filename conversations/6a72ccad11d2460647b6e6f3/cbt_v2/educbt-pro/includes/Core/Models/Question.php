<?php

namespace EduCBTPro\Core\Models;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Question {
    public ?int $id = null;
    public int $school_id = 0;
    public string $subject = '';
    public string $topic = '';
    public string $class = '';
    public string $department = '';
    public string $difficulty = '';
    public string $question_text = '';
    public string $options = '';
    public string $answers = '';
    public string $explanations = '';
    public string $question_type = '';
    public string $status = 'draft';
    public string $created_at = '';
    public string $updated_at = '';
}
