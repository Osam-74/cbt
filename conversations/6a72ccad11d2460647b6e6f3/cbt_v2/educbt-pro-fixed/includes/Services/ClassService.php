<?php

namespace EduCBTPro\Services;

use EduCBTPro\Core\Repository\ClassRepository;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class ClassService {
    private ClassRepository $repository;

    private const DEFAULT_CLASS_STRUCTURE = [
        [ 'class_name' => 'JSS1', 'class_level' => 'Junior Secondary', 'arm' => 'A' ],
        [ 'class_name' => 'JSS2', 'class_level' => 'Junior Secondary', 'arm' => 'A' ],
        [ 'class_name' => 'JSS3', 'class_level' => 'Junior Secondary', 'arm' => 'A' ],
        [ 'class_name' => 'SS1',  'class_level' => 'Senior Secondary', 'arm' => 'A' ],
        [ 'class_name' => 'SS2',  'class_level' => 'Senior Secondary', 'arm' => 'A' ],
        [ 'class_name' => 'SS3',  'class_level' => 'Senior Secondary', 'arm' => 'A' ],
    ];

    public function __construct() {
        $this->repository = new ClassRepository();
    }

    public function list_classes( int $school_id ): array {
        return $this->repository->get_all_classes( $school_id );
    }

    public function create_class( int $school_id, array $data ): int {
        return $this->repository->create_class( $school_id, $data );
    }

    public function seed_default_classes( int $school_id ): int {
        if ( $school_id <= 0 ) {
            return 0;
        }

        $count = 0;
        foreach ( self::DEFAULT_CLASS_STRUCTURE as $class_data ) {
            $id = $this->repository->create_class(
                $school_id,
                [
                    'class_name'  => $class_data['class_name'],
                    'arm'         => $class_data['arm'],
                    'class_level' => $class_data['class_level'],
                    'status'      => 'active',
                ]
            );

            if ( $id > 0 ) {
                $count++;
            }
        }

        return $count;
    }
}
