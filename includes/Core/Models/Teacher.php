<?php

namespace EduCBTPro\Core\Models;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Teacher {
    public ?int $id = null;
    public int $school_id = 0;
    public string $teacher_id = '';
    public string $full_name = '';
    public string $contact_details = '';
    public string $subjects = '';
    public string $assigned_classes = '';
    public string $created_at = '';
    public string $updated_at = '';
}
