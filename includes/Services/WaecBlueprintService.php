<?php

namespace EduCBTPro\Services;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * The WAEC English Language paper blueprint.
 *
 * WASSCE English (Nigeria) is not one paper. It is three, sat over two days:
 *
 *   Paper 1   1h      40 marks   80 MCQs  Objective Test          — OBJECTIVE
 *   Paper 2   2h     100 marks    3 parts  Essay, Comprehension, Summary — THEORY
 *   Paper 3  45m      30 marks   60 MCQs  Test of Orals          — OBJECTIVE
 *   ---------------------------------------------------------------------
 *                   170 marks  (scaled to 100% for grading)
 *
 * Paper 1 and Paper 2 are sat on the same day; Paper 3 on another.
 *
 * The distinction most often got wrong: comprehension and summary are ESSAY work
 * in Paper 2, answered in writing and marked by a teacher. They are not multiple
 * choice. A sixty-item objective paper containing "comprehension" questions is a
 * reasonable mock, but it is not the WAEC structure, and a student prepared only
 * on that meets a different paper in May.
 *
 * Every count and mark below is overridable per school, so a house structure or a
 * shorter internal mock is still possible — but the default is what WAEC sets.
 */
class WaecBlueprintService {

    public const OPTION_KEY = 'educbt_waec_blueprint_';

    /**
     * @return array<string,mixed>
     */
    public static function default_blueprint(): array {
        return [
            'name'        => 'WASSCE English Language (Nigeria)',
            'total_marks' => 170,
            'papers'      => [
                'paper1' => [
                    'label'        => 'Paper 1 — Objective Test',
                    'delivery'     => 'cbt',
                    'exam_type'    => 'objective',
                    'duration_min' => 60,
                    'marks'        => 40,
                    'sections'     => [
                        [ 'key' => 'antonyms', 'label' => 'Section 1 — Antonyms', 'count' => 10, 'marks' => 5,
                          'guide' => 'A sentence with an underlined word. Choose the option most nearly opposite in meaning.' ],
                        [ 'key' => 'synonyms', 'label' => 'Section 2 — Synonyms', 'count' => 10, 'marks' => 5,
                          'guide' => 'A sentence with an underlined word. Choose the option nearest in meaning.' ],
                        [ 'key' => 'idioms', 'label' => 'Section 3 — Idioms and Interpretations', 'count' => 10, 'marks' => 5,
                          'guide' => 'A sentence containing an idiom or complex phrase. Choose the option that best explains its meaning.' ],
                        [ 'key' => 'grammar', 'label' => 'Section 4 — Grammar and Structure', 'count' => 40, 'marks' => 20,
                          'guide' => 'Fill-in-the-blank questions testing concord, tenses, prepositions, phrasal verbs, and clauses.' ],
                        [ 'key' => 'cloze', 'label' => 'Section 5 — Cloze Passage', 'count' => 10, 'marks' => 5, 'needs_passage' => true,
                          'guide' => 'A continuous prose passage with numbered gaps, testing contextual vocabulary and structural flow.' ],
                    ],
                ],
                'paper2' => [
                    'label'        => 'Paper 2 — Essay, Comprehension and Summary',
                    'delivery'     => 'cbt',
                    'exam_type'    => 'theory',
                    'duration_min' => 120,
                    'marks'        => 100,
                    'sections'     => [
                        [ 'key' => 'essay', 'label' => 'Section A — Essay Writing', 'count' => 5, 'marks' => 50,
                          'guide' => 'Five topics across formal letter, informal letter, article for publication, debate/speech, and story/narrative. The candidate answers ONE, in not less than 450 words. Graded on Content (10), Organization (10), Mechanical Accuracy (20), Expression (10).' ],
                        [ 'key' => 'comprehension', 'label' => 'Section B — Comprehension', 'count' => 1, 'marks' => 20, 'needs_passage' => true,
                          'guide' => 'One prose passage with 7-9 questions: literal, inferential, figure of speech, grammatical name and function, and vocabulary replacement.' ],
                        [ 'key' => 'summary', 'label' => 'Section C — Summary', 'count' => 1, 'marks' => 30, 'needs_passage' => true,
                          'guide' => 'One prose passage with 2-3 questions requiring extraction of specific points in the candidate\'s own words. Verbatim lifting carries heavy penalties.' ],
                    ],
                ],
                'paper3' => [
                    'label'        => 'Paper 3 — Test of Orals',
                    'delivery'     => 'cbt',
                    'exam_type'    => 'objective',
                    'duration_min' => 45,
                    'marks'        => 30,
                    'sections'     => [
                        [ 'key' => 'vowels', 'label' => 'Section 1 — Vowels', 'count' => 15, 'marks' => 7.5,
                          'guide' => 'Identify words with the same vowel sound (monophthongs and diphthongs) as the underlined letter(s).' ],
                        [ 'key' => 'consonants', 'label' => 'Section 2 — Consonants', 'count' => 15, 'marks' => 7.5,
                          'guide' => 'Identify words with the same consonant sound as the underlined letter(s).' ],
                        [ 'key' => 'rhymes', 'label' => 'Section 3 — Rhymes', 'count' => 10, 'marks' => 5,
                          'guide' => 'Choose the word that rhymes perfectly with the given word.' ],
                        [ 'key' => 'word_stress', 'label' => 'Section 4 — Word Stress', 'count' => 10, 'marks' => 5,
                          'guide' => 'Identify which syllable in a multisyllabic word carries the primary stress.' ],
                        [ 'key' => 'emphatic_stress', 'label' => 'Section 5 — Emphatic Stress', 'count' => 5, 'marks' => 2.5,
                          'guide' => 'A sentence with one word in CAPITAL LETTERS. Choose the question that prompts that exact sentence as an answer.' ],
                        [ 'key' => 'phonetic_symbols', 'label' => 'Section 6 — Phonetic Symbols', 'count' => 5, 'marks' => 2.5,
                          'guide' => 'Identify the word that matches a specific phonetic symbol provided.' ],
                    ],
                ],
            ],
        ];
    }

    /**
     * The blueprint in force for a school: the WAEC default with any overrides.
     *
     * @return array<string,mixed>
     */
    public static function for_school( int $school_id ): array {
        $blueprint = self::default_blueprint();
        $overrides = (array) get_option( self::OPTION_KEY . $school_id, [] );

        $blueprint['customised'] = ! empty( $overrides );

        if ( empty( $overrides ) ) {
            return $blueprint;
        }

        foreach ( $blueprint['papers'] as $pkey => &$paper ) {
            if ( isset( $overrides[ $pkey ]['duration_min'] ) ) {
                $paper['duration_min'] = absint( $overrides[ $pkey ]['duration_min'] );
            }

            foreach ( $paper['sections'] as &$section ) {
                $skey = $section['key'];

                if ( isset( $overrides[ $pkey ]['sections'][ $skey ]['count'] ) ) {
                    $section['count'] = absint( $overrides[ $pkey ]['sections'][ $skey ]['count'] );
                }

                if ( isset( $overrides[ $pkey ]['sections'][ $skey ]['marks'] ) ) {
                    $section['marks'] = (float) $overrides[ $pkey ]['sections'][ $skey ]['marks'];
                }
            }

            unset( $section );

            $sum = array_sum( array_column( $paper['sections'], 'marks' ) );

            if ( $sum > 0 ) {
                $paper['marks'] = $sum;
            }
        }

        unset( $paper );

        $blueprint['total_marks'] = array_sum( array_column( $blueprint['papers'], 'marks' ) );

        return $blueprint;
    }

    public static function save_overrides( int $school_id, array $overrides ): void {
        update_option( self::OPTION_KEY . $school_id, $overrides, false );
    }

    public static function reset( int $school_id ): void {
        delete_option( self::OPTION_KEY . $school_id );
    }

    /**
     * Sections an author may write into, for a given delivery mode and exam type.
     *
     * An objective CBT set must not offer the essay section: an essay cannot be sat
     * in the objective engine, and offering it guarantees a paper that cannot be
     * composed.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function sections_for( int $school_id, string $delivery_mode, string $exam_type ): array {
        $blueprint = self::for_school( $school_id );
        $out       = [];

        foreach ( $blueprint['papers'] as $pkey => $paper ) {
            if ( $paper['delivery'] !== $delivery_mode || $paper['exam_type'] !== $exam_type ) {
                continue;
            }

            foreach ( $paper['sections'] as $section ) {
                $out[] = [
                    'key'           => $pkey . ':' . $section['key'],
                    'paper_key'     => $pkey,
                    'paper'         => $paper['label'],
                    'label'         => $section['label'],
                    'count'         => absint( $section['count'] ),
                    'marks'         => (float) ( $section['marks'] ?? 0 ),
                    'guide'         => (string) ( $section['guide'] ?? '' ),
                    'needs_passage' => ! empty( $section['needs_passage'] ),
                ];
            }
        }

        return $out;
    }

    /**
     * ALL sections across ALL papers of the blueprint, regardless of exam type.
     *
     * The WAEC panel on the authoring page shows every section of every paper at
     * once — Paper 1 objectives, Paper 2 essay/comprehension/summary, and Paper 3
     * orals — so a teacher can see the full structure and their progress in each
     * part.  This returns the full list without filtering by exam type.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function all_sections( int $school_id ): array {
        $blueprint = self::for_school( $school_id );
        $out       = [];

        foreach ( $blueprint['papers'] as $pkey => $paper ) {
            foreach ( $paper['sections'] as $section ) {
                $out[] = [
                    'key'           => $pkey . ':' . $section['key'],
                    'paper_key'     => $pkey,
                    'paper'         => $paper['label'],
                    'label'         => $section['label'],
                    'count'         => absint( $section['count'] ),
                    'marks'         => (float) ( $section['marks'] ?? 0 ),
                    'guide'         => (string) ( $section['guide'] ?? '' ),
                    'needs_passage' => ! empty( $section['needs_passage'] ),
                ];
            }
        }

        return $out;
    }

    /**
     * How complete a WAEC set is, section by section.
     *
     * Returns progress for the current set's exam type sections only.  The caller
     * (usually the API controller) also fetches the sibling set's progress and
     * merges them so the panel can show all papers at once.
     *
     * @return array{sections:array<int,array<string,mixed>>,complete:bool,total_required:int,total_present:int}
     */
    public static function progress( int $school_id, int $set_id, string $delivery_mode, string $exam_type ): array {
        global $wpdb;

        $questions = $wpdb->prefix . 'educbt_questions';

        $rows = (array) $wpdb->get_results(
            $wpdb->prepare(
                "SELECT section, COUNT(*) AS total
                 FROM {$questions}
                 WHERE question_set_id = %d AND status = 'active'
                 GROUP BY section",
                $set_id
            ),
            ARRAY_A
        );

        $have = [];

        foreach ( $rows as $row ) {
            $have[ (string) $row['section'] ] = absint( $row['total'] );
        }

        $sections = [];
        $required = 0;
        $present  = 0;
        $complete = true;

        foreach ( self::sections_for( $school_id, $delivery_mode, $exam_type ) as $section ) {
            $got = absint( $have[ $section['key'] ] ?? 0 );

            $sections[] = [
                'key'      => $section['key'],
                'paper'    => $section['paper'],
                'label'    => $section['label'],
                'guide'    => $section['guide'],
                'required' => $section['count'],
                'present'  => $got,
                'short'    => max( 0, $section['count'] - $got ),
                'needs_passage' => $section['needs_passage'],
            ];

            $required += $section['count'];
            $present  += min( $got, $section['count'] );

            if ( $got < $section['count'] ) {
                $complete = false;
            }
        }

        return [
            'sections'       => $sections,
            'complete'       => $complete,
            'total_required' => $required,
            'total_present'  => $present,
        ];
    }

    /**
     * Full WAEC progress across ALL papers — the current set and its sibling.
     *
     * The authoring page shows every section of all three papers in one panel.
     * This method queries both the current set and its sibling (the other exam
     * type) and returns a unified section-by-section progress report so the
     * panel's "0/1" counters and the "X of Y" total actually reflect what has
     * been written.
     *
     * @param int    $school_id
     * @param int    $set_id         The current set (objective or theory).
     * @param int    $sibling_set_id The sibling set (the other exam type), or 0.
     * @param string $delivery_mode
     * @param string $exam_type      The current set's exam type.
     * @return array{sections:array<int,array<string,mixed>>,complete:bool,total_required:int,total_present:int}
     */
    public static function full_progress( int $school_id, int $set_id, int $sibling_set_id, string $delivery_mode, string $exam_type ): array {
        global $wpdb;

        $questions_tbl = $wpdb->prefix . 'educbt_questions';

        // Count questions per section for BOTH sets.
        $have = [];

        foreach ( [ [ $set_id, 'current' ], [ $sibling_set_id, 'sibling' ] ] as $pair ) {
            $sid = $pair[0];
            if ( $sid <= 0 ) {
                continue;
            }

            $rows = (array) $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT section, COUNT(*) AS total
                     FROM {$questions_tbl}
                     WHERE question_set_id = %d AND status = 'active'
                     GROUP BY section",
                    $sid
                ),
                ARRAY_A
            );

            foreach ( $rows as $row ) {
                $key = (string) $row['section'];
                // A section belongs to one paper only; there is no overlap between
                // objective and theory sets, so we can safely take the max.
                $have[ $key ] = max( absint( $have[ $key ] ?? 0 ), absint( $row['total'] ) );
            }
        }

        $sections = [];
        $required = 0;
        $present  = 0;
        $complete = true;

        foreach ( self::all_sections( $school_id ) as $section ) {
            $got = absint( $have[ $section['key'] ] ?? 0 );

            $sections[] = [
                'key'      => $section['key'],
                'paper'    => $section['paper'],
                'label'    => $section['label'],
                'guide'    => $section['guide'],
                'required' => $section['count'],
                'present'  => $got,
                'short'    => max( 0, $section['count'] - $got ),
                'needs_passage' => $section['needs_passage'],
            ];

            $required += $section['count'];
            $present  += min( $got, $section['count'] );

            if ( $got < $section['count'] ) {
                $complete = false;
            }
        }

        return [
            'sections'       => $sections,
            'complete'       => $complete,
            'total_required' => $required,
            'total_present'  => $present,
        ];
    }

    /**
     * The WAEC weighting: how each paper's raw marks convert to 100%.
     *
     * Paper 1 (40 marks)  -> 40%   (1:1, no scaling)
     * Paper 2 (100 marks) -> 50%   (divide by 2)
     * Paper 3 (30 marks)  -> 10%   (divide by 3)
     *
     * These are the standard WASSCE English Language weights.  A student who
     * scores 30/40 + 70/100 + 18/30 gets 30 + 35 + 6 = 71%.
     *
     * @return array<string,float>  paper_key => percentage weight (sums to 100)
     */
    public static function paper_weights( int $school_id ): array {
        $blueprint = self::for_school( $school_id );
        $weights   = [];

        foreach ( $blueprint['papers'] as $pkey => $paper ) {
            $weights[ $pkey ] = self::weight_of( $blueprint, $pkey );
        }

        return $weights;
    }

    /**
     * Scale a set of raw paper scores into the 100% WAEC total.
     *
     * @param array<string,float> $raw_scores  paper_key => raw marks obtained
     * @return array{total:float,papers:array<string,float>}  scaled percentage per paper + overall
     */
    public static function scale_to_100( int $school_id, array $raw_scores ): array {
        $blueprint = self::for_school( $school_id );
        $weights   = self::paper_weights( $school_id );
        $scaled    = [];
        $total     = 0.0;

        foreach ( $blueprint['papers'] as $pkey => $paper ) {
            $raw       = (float) ( $raw_scores[ $pkey ] ?? 0 );
            $paper_max = (float) ( $paper['marks'] ?? 1 );
            $weight    = (float) ( $weights[ $pkey ] ?? 0 );

            if ( $paper_max <= 0 ) {
                $scaled[ $pkey ] = 0.0;
                continue;
            }

            // raw/paper_max * weight = contribution to the 100% total
            $contribution = round( ( $raw / $paper_max ) * $weight, 2 );
            $scaled[ $pkey ] = $contribution;
            $total += $contribution;
        }

        return [
            'total'  => round( $total, 2 ),
            'papers' => $scaled,
        ];
    }

    /**
     * Scale a single paper's raw score into its WAEC weight contribution.
     *
     * Useful in the marking page where each paper is marked independently.
     *
     * @param string $paper_key  'paper1', 'paper2', or 'paper3'
     * @param float  $raw_score  The student's raw marks on that paper
     * @return float  The scaled contribution toward the 100% total
     */
    public static function scale_paper( int $school_id, string $paper_key, float $raw_score ): float {
        $blueprint = self::for_school( $school_id );
        $paper     = $blueprint['papers'][ $paper_key ] ?? null;

        if ( ! $paper ) {
            return 0.0;
        }

        $paper_max = (float) ( $paper['marks'] ?? 1 );
        $weight    = self::weight_of( $blueprint, $paper_key );

        if ( $paper_max <= 0 ) {
            return 0.0;
        }

        return round( ( $raw_score / $paper_max ) * $weight, 2 );
    }

    /**
     * A paper's share of the final 100%, derived from its marks.
     *
     * WHY THIS IS NOT A FIXED NUMBER
     * ------------------------------
     * The weights were hardcoded at Paper 1 = 40%, Paper 2 = 50%, Paper 3 = 10%.
     * Those do not correspond to what the papers are worth:
     *
     *   Paper 1   40 raw marks  =  23.5% of 170, not 40%
     *   Paper 2  100 raw marks  =  58.8% of 170, not 50%
     *   Paper 3   30 raw marks  =  17.6% of 170, not 10%
     *
     * Under the fixed weights, eighty objective questions carried nearly twice the
     * influence they should, while the essay paper — the bulk of the candidate's
     * actual work — carried less. A student could lose the essay paper badly and
     * still be rescued by the multiple-choice section.
     *
     * WAEC scales the 170 raw marks down to a percentage. That is proportional, so
     * the weight is simply the paper's marks as a share of the total. Deriving it
     * also means a school that adjusts a section's marks cannot leave a stale weight
     * behind pointing at the old figure.
     */
    private static function weight_of( array $blueprint, string $paper_key ): float {
        $total = (float) array_sum( array_column( $blueprint['papers'], 'marks' ) );
        $paper = $blueprint['papers'][ $paper_key ] ?? null;

        if ( ! $paper || $total <= 0 ) {
            return 0.0;
        }

        return round( ( (float) $paper['marks'] / $total ) * 100, 2 );
    }
}
