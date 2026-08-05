<?php

namespace EduCBTPro\Services;

use EduCBTPro\Core\EventDispatcher;
use EduCBTPro\Core\Repository\TeacherRepository;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class TeacherService {
    private TeacherRepository $repository;

    public function __construct( ?TeacherRepository $repository = null ) {
        $this->repository = $repository ?? new TeacherRepository();
    }

    public function list_teachers( int $school_id ): array {
        return $this->repository->get_all_teachers( $school_id );
    }

    public function create_teacher( int $school_id, array $data ): int {
        $id = $this->repository->create_teacher( $school_id, $data );
        if ( $id > 0 ) {
            EventDispatcher::action( 'teacher_created', [
                'school_id'  => $school_id,
                'teacher_id' => $id,
                'data'       => $data,
            ] );
        }
        return $id;
    }

    public function get_teacher_by_id( int $school_id, int $teacher_id ): ?array {
        return $this->repository->get_teacher_by_id( $school_id, $teacher_id );
    }

    public function update_teacher( int $school_id, int $teacher_id, array $data ): bool {
        $updated = $this->repository->update_teacher( $school_id, $teacher_id, $data );
        if ( $updated ) {
            EventDispatcher::action( 'teacher_updated', [
                'school_id'  => $school_id,
                'teacher_id' => $teacher_id,
                'data'       => $data,
            ] );
        }

        return $updated;
    }
}
