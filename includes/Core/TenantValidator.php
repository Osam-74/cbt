<?php

namespace EduCBTPro\Core;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class TenantValidator {
    public function validate_school_code( string $school_code ): bool {
        return preg_match( '/^[A-Z0-9\-\_]+$/i', $school_code ) === 1;
    }

    public function validate_school_name( string $school_name ): bool {
        return ! empty( trim( $school_name ) );
    }
}
