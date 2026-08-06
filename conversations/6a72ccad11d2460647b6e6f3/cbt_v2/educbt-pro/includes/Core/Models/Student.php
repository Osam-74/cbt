<?php

namespace EduCBTPro\Core\Models;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Student {
    public ?int $id = null;
    public int $school_id = 0;
    public string $admission_number = '';
    public string $registration_number = '';
    public string $student_id = '';
    public string $passport_photo = '';
    public string $full_name = '';
    public string $gender = '';
    public string $date_of_birth = '';
    public string $parent_information = '';
    public string $address = '';
    public string $class = '';
    public string $arm = '';
    public string $session_year = '';
    public string $status = 'active';
    public string $created_at = '';
    public string $updated_at = '';
}
