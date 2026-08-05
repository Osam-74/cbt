<?php

namespace EduCBTPro\Core\Models;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class School {
    public ?int $id = null;
    public ?string $school_name = null;
    public ?string $school_code = null;
    public ?string $logo = null;
    public ?string $address = null;
    public ?string $phone = null;
    public ?string $email = null;
    public ?string $website = null;
    public ?string $principal_name = null;
    public ?string $academic_settings = null;
    public ?string $report_settings = null;
    public ?string $created_at = null;
    public ?string $updated_at = null;
}
