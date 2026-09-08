<?php

namespace EduCBTPro\Data;

use EduCBTPro\Core\Schema;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * PHASE 6b — trial question bank.
 *
 * These questions are ORIGINAL, written against published WAEC/BECE syllabus topic
 * areas rather than copied from any past paper or question website. That distinction
 * matters twice over: reproducing a commercial question bank would be copyright
 * infringement, and past-paper scrapes are riddled with the wrong-answer-key problem
 * the Phase 1 backfill kept surfacing.
 *
 * Coverage follows the published core:
 *
 *   English Language  lexis (synonyms, antonyms, idioms) and structure (tenses,
 *                     concord, parts of speech, punctuation) — the two halves of
 *                     the objective paper
 *   Mathematics       number and numeration, algebra, geometry, mensuration,
 *                     statistics
 *   Civic Education   citizenship, human rights, law and order, democracy, values,
 *                     national consciousness
 *   Basic Science     junior-level science for the JSS band
 *
 * Every question carries an EXPLANATION. Explanations exist only in trial mode —
 * a real exam must never show a student why an answer was right, or the paper
 * becomes an answer key the moment the first student finishes.
 */
class TrialQuestionSeed {

    public const BAND_JUNIOR = 'junior';
    public const BAND_SENIOR = 'senior';
    public const BAND_BOTH   = 'both';

    /**
     * Idempotent. Keyed on `seed_ref`, so re-running updates wording rather than
     * duplicating, and a school that has been running for a year does not suddenly
     * get every trial question twice.
     */
    public static function install(): int {
        global $wpdb;

        $table    = Schema::table( 'trial_questions' );
        $inserted = 0;

        foreach ( self::questions() as $question ) {
            $wpdb->query(
                $wpdb->prepare(
                    "INSERT INTO {$table}
                        (subject_code, subject_name, level_band, topic, difficulty,
                         question_text, options, answer_key, explanation, seed_ref, status)
                     VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, 'active')
                     ON DUPLICATE KEY UPDATE
                        question_text = VALUES(question_text),
                        options = VALUES(options),
                        answer_key = VALUES(answer_key),
                        explanation = VALUES(explanation),
                        topic = VALUES(topic)",
                    $question['subject_code'],
                    $question['subject_name'],
                    $question['level_band'],
                    $question['topic'],
                    $question['difficulty'],
                    $question['question_text'],
                    (string) wp_json_encode( $question['options'] ),
                    $question['answer_key'],
                    $question['explanation'],
                    $question['seed_ref']
                )
            );

            $inserted++;
        }

        return $inserted;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public static function subjects(): array {
        return [
            [ 'code' => 'ENG', 'name' => 'English Language', 'band' => self::BAND_BOTH ],
            [ 'code' => 'MTH', 'name' => 'Mathematics', 'band' => self::BAND_BOTH ],
            [ 'code' => 'CVE', 'name' => 'Civic Education', 'band' => self::BAND_BOTH ],
            [ 'code' => 'BSC', 'name' => 'Basic Science', 'band' => self::BAND_JUNIOR  ],
            [ 'code' => 'GST', 'name' => 'General Studies', 'band' => self::BAND_BOTH ],
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public static function questions(): array {
        $q = [];

        // =============================================================
        // ENGLISH LANGUAGE — lexis
        // =============================================================

        $q[] = self::make( 'ENG', 'English Language', self::BAND_BOTH, 'Lexis: synonyms', 'easy',
            'Choose the option that is NEAREST in meaning to the underlined word: The headmaster gave a <u>concise</u> report.',
            [ 'A' => 'Lengthy', 'B' => 'Brief', 'C' => 'Confusing', 'D' => 'Detailed' ], 'B',
            '"Concise" means expressed in few words. "Brief" is its closest synonym. "Lengthy" and "detailed" are opposites, and "confusing" describes clarity rather than length.'
        );

        $q[] = self::make( 'ENG', 'English Language', self::BAND_BOTH, 'Lexis: antonyms', 'easy',
            'Choose the option OPPOSITE in meaning to the underlined word: The child was <u>obedient</u> to his parents.',
            [ 'A' => 'Loyal', 'B' => 'Respectful', 'C' => 'Defiant', 'D' => 'Gentle' ], 'C',
            '"Obedient" means willing to follow instructions. "Defiant" means openly refusing to obey, so it is the direct opposite. Loyal, respectful and gentle are all closer in meaning than opposite.'
        );

        $q[] = self::make( 'ENG', 'English Language', self::BAND_SENIOR, 'Lexis: idioms', 'medium',
            'The two brothers have been <u>at loggerheads</u> since their father died. This means they have been',
            [ 'A' => 'in strong disagreement', 'B' => 'living far apart', 'C' => 'sharing responsibilities', 'D' => 'travelling together' ], 'A',
            'An idiom cannot be understood from the dictionary meanings of its separate words. "At loggerheads" means in sharp disagreement or dispute.'
        );

        $q[] = self::make( 'ENG', 'English Language', self::BAND_SENIOR, 'Lexis: idioms', 'medium',
            'She accepted the story <u>hook, line and sinker</u>. This means she accepted it',
            [ 'A' => 'with serious doubt', 'B' => 'completely and without question', 'C' => 'only in part', 'D' => 'after long investigation' ], 'B',
            'This fishing idiom describes believing something entirely and uncritically. Note that the meaning has nothing to do with the individual words "hook", "line" or "sinker".'
        );

        $q[] = self::make( 'ENG', 'English Language', self::BAND_BOTH, 'Lexis: word choice', 'medium',
            'Choose the word that best completes the sentence: The government has promised to ___ the new hospital next month.',
            [ 'A' => 'commission', 'B' => 'commence', 'C' => 'commend', 'D' => 'comment' ], 'A',
            'To "commission" a building is to formally open it for use. "Commence" means to begin, "commend" means to praise, and "comment" means to remark. These are commonly confused word pairs.'
        );

        // ENGLISH — structure

        $q[] = self::make( 'ENG', 'English Language', self::BAND_BOTH, 'Structure: concord', 'easy',
            'Choose the option that correctly completes the sentence: Neither the teacher nor the students ___ arrived.',
            [ 'A' => 'has', 'B' => 'have', 'C' => 'is', 'D' => 'was' ], 'B',
            'With "neither...nor", the verb agrees with the subject CLOSEST to it. "Students" is plural and nearest the verb, so "have" is correct. If the order were reversed — neither the students nor the teacher — "has" would be correct.'
        );

        $q[] = self::make( 'ENG', 'English Language', self::BAND_BOTH, 'Structure: tenses', 'easy',
            'Choose the option that correctly completes the sentence: By the time we arrived, the match ___.',
            [ 'A' => 'has started', 'B' => 'had started', 'C' => 'starts', 'D' => 'is starting' ], 'B',
            'Two past events: the match started FIRST, then we arrived. The earlier of two past actions takes the past perfect, "had started".'
        );

        $q[] = self::make( 'ENG', 'English Language', self::BAND_BOTH, 'Structure: parts of speech', 'medium',
            'In the sentence "She sang beautifully at the concert", the word "beautifully" is',
            [ 'A' => 'an adjective', 'B' => 'an adverb', 'C' => 'a noun', 'D' => 'a preposition' ], 'B',
            'It describes HOW she sang, modifying the verb "sang". Words that modify verbs are adverbs. An adjective would modify a noun, as in "a beautiful song".'
        );

        $q[] = self::make( 'ENG', 'English Language', self::BAND_SENIOR, 'Structure: question tags', 'medium',
            'Choose the correct question tag: You have finished your homework, ___',
            [ 'A' => "haven't you?", 'B' => "have you?", 'C' => "didn't you?", 'D' => "don't you?" ], 'A',
            'A positive statement takes a negative tag, and the tag repeats the auxiliary verb of the statement. The auxiliary here is "have", so the tag is "haven\'t you?".'
        );

        $q[] = self::make( 'ENG', 'English Language', self::BAND_BOTH, 'Structure: punctuation', 'easy',
            'Which sentence is correctly punctuated?',
            [
                'A' => 'My brother, who lives in Kano is a doctor.',
                'B' => 'My brother who lives in Kano, is a doctor.',
                'C' => 'My brother, who lives in Kano, is a doctor.',
                'D' => 'My brother who, lives in Kano is a doctor.',
            ], 'C',
            'A non-defining relative clause — extra information that could be removed — must be enclosed by commas on BOTH sides. Opening a pair of commas and not closing it is the most common error here.'
        );

        // =============================================================
        // MATHEMATICS
        // =============================================================

        $q[] = self::make( 'MTH', 'Mathematics', self::BAND_BOTH, 'Number and numeration: percentages', 'easy',
            'A trader bought a bag of rice for ₦45,000 and sold it for ₦54,000. What is the percentage profit?',
            [ 'A' => '9%', 'B' => '16.7%', 'C' => '20%', 'D' => '25%' ], 'C',
            'Profit = 54,000 − 45,000 = 9,000. Percentage profit is calculated on the COST price: (9,000 ÷ 45,000) × 100 = 20%. Dividing by the selling price instead gives 16.7%, which is the usual mistake.'
        );

        $q[] = self::make( 'MTH', 'Mathematics', self::BAND_BOTH, 'Number and numeration: ratio', 'easy',
            'Share ₦12,000 between Ada and Bala in the ratio 3:5. How much does Bala receive?',
            [ 'A' => '₦4,500', 'B' => '₦6,000', 'C' => '₦7,500', 'D' => '₦8,000' ], 'C',
            'Total parts = 3 + 5 = 8. One part = 12,000 ÷ 8 = 1,500. Bala has 5 parts = 5 × 1,500 = ₦7,500. Ada receives ₦4,500, and the two together check back to ₦12,000.'
        );

        $q[] = self::make( 'MTH', 'Mathematics', self::BAND_BOTH, 'Algebra: linear equations', 'easy',
            'Solve for x: 3x − 7 = 14',
            [ 'A' => 'x = 5', 'B' => 'x = 7', 'C' => 'x = 21', 'D' => 'x = 3' ], 'B',
            'Add 7 to both sides: 3x = 21. Divide both sides by 3: x = 7. Check by substituting: 3(7) − 7 = 21 − 7 = 14.'
        );

        $q[] = self::make( 'MTH', 'Mathematics', self::BAND_SENIOR, 'Algebra: simultaneous equations', 'medium',
            'If x + y = 10 and x − y = 4, find the value of x.',
            [ 'A' => '3', 'B' => '5', 'C' => '7', 'D' => '14' ], 'C',
            'Adding the two equations eliminates y: (x + y) + (x − y) = 10 + 4, so 2x = 14 and x = 7. Substituting back gives y = 3.'
        );

        $q[] = self::make( 'MTH', 'Mathematics', self::BAND_BOTH, 'Mensuration: area', 'medium',
            'A rectangular field is 24 m long and 15 m wide. What is its area?',
            [ 'A' => '39 m²', 'B' => '78 m²', 'C' => '180 m²', 'D' => '360 m²' ], 'D',
            'Area of a rectangle = length × width = 24 × 15 = 360 m². Note that 78 m is the PERIMETER, 2(24 + 15), which is the common confusion.'
        );

        $q[] = self::make( 'MTH', 'Mathematics', self::BAND_SENIOR, 'Mensuration: circles', 'medium',
            'Find the circumference of a circle of radius 7 cm. (Take π = 22/7)',
            [ 'A' => '22 cm', 'B' => '44 cm', 'C' => '154 cm', 'D' => '308 cm' ], 'B',
            'Circumference = 2πr = 2 × 22/7 × 7 = 44 cm. 154 cm² is the AREA (πr²), which is what students pick when they use the wrong formula.'
        );

        $q[] = self::make( 'MTH', 'Mathematics', self::BAND_BOTH, 'Geometry: angles', 'easy',
            'Two angles of a triangle are 65° and 48°. What is the third angle?',
            [ 'A' => '57°', 'B' => '67°', 'C' => '77°', 'D' => '113°' ], 'B',
            'Angles in a triangle sum to 180°. 180 − (65 + 48) = 180 − 113 = 67°.'
        );

        $q[] = self::make( 'MTH', 'Mathematics', self::BAND_BOTH, 'Statistics: mean', 'easy',
            'Find the mean of: 12, 15, 18, 20, 25',
            [ 'A' => '17', 'B' => '18', 'C' => '19', 'D' => '20' ], 'B',
            'Sum = 12 + 15 + 18 + 20 + 25 = 90. Mean = 90 ÷ 5 = 18. Here the mean and median happen to coincide, which is not generally true.'
        );

        $q[] = self::make( 'MTH', 'Mathematics', self::BAND_SENIOR, 'Number: indices', 'medium',
            'Simplify: 2³ × 2⁴',
            [ 'A' => '2⁷', 'B' => '2¹²', 'C' => '4⁷', 'D' => '2¹' ], 'A',
            'When multiplying powers of the SAME base, add the indices: 2³ × 2⁴ = 2³⁺⁴ = 2⁷. Multiplying the indices (giving 2¹²) is the standard error.'
        );

        $q[] = self::make( 'MTH', 'Mathematics', self::BAND_BOTH, 'Number: fractions', 'easy',
            'Express 0.375 as a fraction in its lowest terms.',
            [ 'A' => '3/8', 'B' => '3/5', 'C' => '375/100', 'D' => '5/8' ], 'A',
            '0.375 = 375/1000. Dividing numerator and denominator by 125 gives 3/8. Always reduce to lowest terms unless the question says otherwise.'
        );

        // =============================================================
        // CIVIC EDUCATION
        // =============================================================

        $q[] = self::make( 'CVE', 'Civic Education', self::BAND_BOTH, 'Citizenship', 'easy',
            'Which of the following is NOT a method of acquiring Nigerian citizenship?',
            [ 'A' => 'By birth', 'B' => 'By registration', 'C' => 'By naturalisation', 'D' => 'By employment' ], 'D',
            'The Constitution provides three routes to citizenship: birth, registration and naturalisation. Taking up employment in a country confers no citizenship at all.'
        );

        $q[] = self::make( 'CVE', 'Civic Education', self::BAND_BOTH, 'Duties of citizens', 'easy',
            'Which of these is a DUTY rather than a right of a citizen?',
            [ 'A' => 'Payment of taxes', 'B' => 'Freedom of speech', 'C' => 'Right to fair hearing', 'D' => 'Freedom of movement' ], 'A',
            'Rights are what a citizen is entitled to; duties are what a citizen owes. Paying tax, obeying the law and defending the country are duties. The other three options are rights.'
        );

        $q[] = self::make( 'CVE', 'Civic Education', self::BAND_SENIOR, 'Rule of law', 'medium',
            'The principle that no one is above the law, including those who govern, is known as',
            [ 'A' => 'separation of powers', 'B' => 'the rule of law', 'C' => 'federalism', 'D' => 'checks and balances' ], 'B',
            'The rule of law holds that everyone is equally subject to the law. Separation of powers divides government into arms; checks and balances lets each arm limit the others.'
        );

        $q[] = self::make( 'CVE', 'Civic Education', self::BAND_BOTH, 'Arms of government', 'easy',
            'Which arm of government is responsible for interpreting the law?',
            [ 'A' => 'The legislature', 'B' => 'The executive', 'C' => 'The judiciary', 'D' => 'The civil service' ], 'C',
            'The legislature makes laws, the executive implements them, and the judiciary interprets them and settles disputes. The civil service is part of the executive, not a separate arm.'
        );

        $q[] = self::make( 'CVE', 'Civic Education', self::BAND_SENIOR, 'Democracy', 'medium',
            'Which of the following is a feature of democracy?',
            [ 'A' => 'Rule by a single unelected leader', 'B' => 'Periodic free and fair elections', 'C' => 'Suppression of opposition parties', 'D' => 'Censorship of the press' ], 'B',
            'Democracy rests on popular participation through regular free and fair elections, an independent press, and a tolerated opposition. The other options describe authoritarian rule.'
        );

        $q[] = self::make( 'CVE', 'Civic Education', self::BAND_BOTH, 'Human rights', 'easy',
            'The right to life, dignity and fair hearing are examples of',
            [ 'A' => 'political privileges', 'B' => 'fundamental human rights', 'C' => 'civic duties', 'D' => 'social customs' ], 'B',
            'These are fundamental human rights, guaranteed by the Constitution and international instruments, and belonging to every person by virtue of being human rather than being granted by government.'
        );

        $q[] = self::make( 'CVE', 'Civic Education', self::BAND_SENIOR, 'National values', 'easy',
            'Which of the following best describes the value of INTEGRITY?',
            [ 'A' => 'Being wealthy and influential', 'B' => 'Being honest and having strong moral principles', 'C' => 'Being popular in the community', 'D' => 'Being loyal to one ethnic group' ], 'B',
            'Integrity is honesty and firm adherence to moral principles, especially when nobody is watching. Wealth, popularity and ethnic loyalty are not moral values.'
        );

        $q[] = self::make( 'CVE', 'Civic Education', self::BAND_BOTH, 'Drug abuse', 'easy',
            'Which of the following is a consequence of drug abuse?',
            [ 'A' => 'Improved academic performance', 'B' => 'Better physical health', 'C' => 'Impaired judgement and health damage', 'D' => 'Increased family harmony' ], 'C',
            'Drug abuse damages physical and mental health, impairs judgement, and harms relationships and study. Any option suggesting a benefit is incorrect.'
        );

        // =============================================================
        // BASIC SCIENCE — junior band
        // =============================================================

        $q[] = self::make( 'BSC', 'Basic Science', self::BAND_JUNIOR, 'Living things', 'easy',
            'Which process do green plants use to make their own food?',
            [ 'A' => 'Respiration', 'B' => 'Photosynthesis', 'C' => 'Digestion', 'D' => 'Excretion' ], 'B',
            'Photosynthesis uses sunlight, water and carbon dioxide to make food in the leaves. Respiration is the release of energy from food, which plants also do — but it is not how food is made.'
        );

        $q[] = self::make( 'BSC', 'Basic Science', self::BAND_JUNIOR, 'Matter', 'easy',
            'Which of the following is a mixture rather than a pure substance?',
            [ 'A' => 'Distilled water', 'B' => 'Oxygen gas', 'C' => 'Air', 'D' => 'Common salt' ], 'C',
            'Air is a mixture of nitrogen, oxygen, carbon dioxide and other gases, which can be separated by physical means. The others each consist of one substance.'
        );

        $q[] = self::make( 'BSC', 'Basic Science', self::BAND_JUNIOR, 'Energy', 'easy',
            'The energy possessed by a body because of its motion is called',
            [ 'A' => 'potential energy', 'B' => 'kinetic energy', 'C' => 'chemical energy', 'D' => 'nuclear energy' ], 'B',
            'Kinetic energy is energy of motion. Potential energy is stored energy due to position or state — a stone held above the ground has potential energy, and gains kinetic energy as it falls.'
        );

        $q[] = self::make( 'BSC', 'Basic Science', self::BAND_JUNIOR, 'Human body', 'easy',
            'Which organ pumps blood round the human body?',
            [ 'A' => 'The lungs', 'B' => 'The liver', 'C' => 'The heart', 'D' => 'The kidney' ], 'C',
            'The heart pumps blood through the circulatory system. The lungs exchange gases, the liver processes nutrients and toxins, and the kidneys filter waste from blood.'
        );

        $q[] = self::make( 'BSC', 'Basic Science', self::BAND_JUNIOR, 'Matter: states', 'easy',
            'What happens to the particles of a solid when it is heated and melts?',
            [ 'A' => 'They stop moving completely', 'B' => 'They move further apart and more freely', 'C' => 'They become heavier', 'D' => 'They disappear' ], 'B',
            'Heating gives particles more energy, so they vibrate harder, break free of their fixed positions and move more freely. The particles themselves do not change in mass or number.'
        );

        $q[] = self::make( 'BSC', 'Basic Science', self::BAND_JUNIOR, 'Environment', 'easy',
            'Which of the following is a renewable source of energy?',
            [ 'A' => 'Coal', 'B' => 'Petroleum', 'C' => 'Solar energy', 'D' => 'Natural gas' ], 'C',
            'Solar energy is replenished continuously by the sun. Coal, petroleum and natural gas are fossil fuels, formed over millions of years and used far faster than they are replaced.'
        );

        $q[] = self::make( 'BSC', 'Basic Science', self::BAND_JUNIOR, 'Living things', 'easy',
            'Which part of a flowering plant absorbs water and mineral salts from the soil?',
            [ 'A' => 'The leaf', 'B' => 'The stem', 'C' => 'The root', 'D' => 'The flower' ], 'C',
            'Roots absorb water and dissolved minerals through root hairs. The stem transports them upward, the leaf makes food, and the flower is for reproduction.'
        );

        $q[] = self::make( 'BSC', 'Basic Science', self::BAND_JUNIOR, 'Health', 'easy',
            'Which of these is a deficiency disease caused by lack of vitamin C?',
            [ 'A' => 'Scurvy', 'B' => 'Malaria', 'C' => 'Cholera', 'D' => 'Typhoid' ], 'A',
            'Scurvy results from insufficient vitamin C, found in citrus fruits and vegetables. Malaria, cholera and typhoid are caused by parasites or bacteria, not by a missing nutrient.'
        );

        $q[] = self::make( 'BSC', 'Basic Science', self::BAND_JUNIOR, 'Force and motion', 'easy',
            'The force that opposes motion between two surfaces in contact is called',
            [ 'A' => 'gravity', 'B' => 'friction', 'C' => 'tension', 'D' => 'upthrust' ], 'B',
            'Friction acts between surfaces in contact and opposes their relative motion. Gravity pulls objects toward the earth, and upthrust is the upward push of a fluid on an object in it.'
        );

        $q[] = self::make( 'BSC', 'Basic Science', self::BAND_JUNIOR, 'Matter', 'medium',
            'Which method would best separate a mixture of sand and water?',
            [ 'A' => 'Evaporation', 'B' => 'Filtration', 'C' => 'Magnetisation', 'D' => 'Distillation' ], 'B',
            'Sand is insoluble, so filtration traps it while water passes through. Evaporation would remove the water and leave the sand, but you would lose the water — filtration recovers both.'
        );

        // Seed refs are assigned HERE, from a counter local to this call.

        // =============================================================
        // ADDITIONAL ENGLISH — questions 11-25
        // =============================================================

        $q[] = self::make( 'ENG', 'English Language', self::BAND_BOTH, 'Lexis: synonyms', 'medium',
            'Choose the option NEAREST in meaning: The judge was <u>impartial</u> in his ruling.',
            [ 'A' => 'Biased', 'B' => 'Fair', 'C' => 'Strict', 'D' => 'Lenient' ], 'B',
            '"Impartial" means treating all sides equally without bias. "Fair" is the closest synonym. "Biased" is the opposite, while "strict" and "lenient" describe severity, not fairness.'
        );

        $q[] = self::make( 'ENG', 'English Language', self::BAND_BOTH, 'Lexis: antonyms', 'medium',
            'Choose the option OPPOSITE in meaning: The witness gave a <u>vague</u> description of the incident.',
            [ 'A' => 'Clear', 'B' => 'Brief', 'C' => 'False', 'D' => 'Detailed' ], 'A',
            '"Vague" means unclear or indistinct. The opposite is "clear." "Detailed" is not quite the opposite — something can be vague yet detailed in the wrong direction. "Brief" relates to length, not clarity.'
        );

        $q[] = self::make( 'ENG', 'English Language', self::BAND_BOTH, 'Structure: tenses', 'medium',
            'By the time we arrived, the meeting ___ for thirty minutes.',
            [ 'A' => 'has been going on', 'B' => 'had been going on', 'C' => 'is going on', 'D' => 'was going on' ], 'B',
            'Past perfect continuous ("had been going on") is needed because the meeting started before a past event (our arrival) and was still in progress. "Has been" is present perfect, which does not fit a past narrative.'
        );

        $q[] = self::make( 'ENG', 'English Language', self::BAND_BOTH, 'Structure: concord', 'medium',
            'Neither the principal nor the teachers ___ aware of the change.',
            [ 'A' => 'was', 'B' => 'were', 'C' => 'is', 'D' => 'has' ], 'B',
            'With "neither...nor," the verb agrees with the nearer subject — "teachers" (plural) — so "were" is correct. "Was" would agree with "principal," but that is the farther subject.'
        );

        $q[] = self::make( 'ENG', 'English Language', self::BAND_BOTH, 'Lexis: idioms', 'medium',
            'The idiom "to bite the bullet" means to',
            [ 'A' => 'eat quickly', 'B' => 'endure something unpleasant with courage', 'C' => 'attack someone', 'D' => 'waste time' ], 'B',
            '"Bite the bullet" originated from soldiers biting bullets during surgery without anaesthesia. It means facing a difficult or unpleasant situation bravely rather than avoiding it.'
        );

        $q[] = self::make( 'ENG', 'English Language', self::BAND_BOTH, 'Structure: question tags', 'medium',
            'Choose the correct question tag: "You have never been to Abuja, ___?"',
            [ 'A' => 'have you', 'B' => 'haven\'t you', 'C' => 'did you', 'D' => 'aren\'t you' ], 'A',
            'When the main clause is negative ("never"), the tag is positive: "have you." "Haven\'t you" would create a double negative. The auxiliary "have" matches the main clause.'
        );

        $q[] = self::make( 'ENG', 'English Language', self::BAND_BOTH, 'Lexis: word choice', 'medium',
            'The students were advised to ___ their notes before the examination.',
            [ 'A' => 'revise', 'B' => 'review', 'C' => 'read', 'D' => 'memorise' ], 'A',
            '"Revise" specifically means to study material again in preparation for an exam, which is the intended meaning. "Review" means to look over critically, "read" is too general, and "memorise" implies rote learning.'
        );

        $q[] = self::make( 'ENG', 'English Language', self::BAND_BOTH, 'Structure: parts of speech', 'easy',
            'Identify the adverb in: "She sang beautifully at the concert."',
            [ 'A' => 'She', 'B' => 'sang', 'C' => 'beautifully', 'D' => 'concert' ], 'C',
            '"Beautifully" modifies the verb "sang" and tells how she sang. Adverbs modify verbs, adjectives, or other adverbs, and often end in "-ly."'
        );

        $q[] = self::make( 'ENG', 'English Language', self::BAND_BOTH, 'Structure: punctuation', 'medium',
            'Which sentence is correctly punctuated?',
            [ 'A' => 'The teacher said, "Submit your work on time."', 'B' => 'The teacher said "submit your work on time".', 'C' => 'The teacher said, submit your work on time.', 'D' => '"The teacher said, submit your work on time."' ], 'A',
            'Direct speech requires a comma before the opening quotation mark, and the period goes inside the closing quotation mark. Option A follows both rules.'
        );

        $q[] = self::make( 'ENG', 'English Language', self::BAND_BOTH, 'Lexis: synonyms', 'medium',
            'Choose the option NEAREST in meaning: The school has a <u>rigorous</u> curriculum.',
            [ 'A' => 'Simple', 'B' => 'Thorough', 'C' => 'Flexible', 'D' => 'Outdated' ], 'B',
            '"Rigorous" means extremely thorough and demanding. "Thorough" is the closest synonym. "Simple" and "flexible" are opposites, and "outdated" relates to age, not rigor.'
        );

        $q[] = self::make( 'ENG', 'English Language', self::BAND_BOTH, 'Structure: concord', 'medium',
            'The committee ___ divided on the issue.',
            [ 'A' => 'was', 'B' => 'were', 'C' => 'is', 'D' => 'be' ], 'B',
            '"Committee" is a collective noun. When members act individually (divided = disagreement), the plural verb "were" is appropriate. "Was" would imply the committee acted as one body, which contradicts "divided."'
        );

        $q[] = self::make( 'ENG', 'English Language', self::BAND_BOTH, 'Lexis: antonyms', 'medium',
            'Choose the option OPPOSITE in meaning: His reaction was <u>spontaneous</u>.',
            [ 'A' => 'Planned', 'B' => 'Quick', 'C' => 'Emotional', 'D' => 'Silent' ], 'A',
            '"Spontaneous" means done without planning. The opposite is "planned." "Quick" describes speed, not planning, and "emotional" and "silent" are unrelated to spontaneity.'
        );

        $q[] = self::make( 'ENG', 'English Language', self::BAND_BOTH, 'Lexis: idioms', 'medium',
            '"To let the cat out of the bag" means to',
            [ 'A' => 'release an animal', 'B' => 'reveal a secret', 'C' => 'cause trouble', 'D' => 'make a mistake' ], 'B',
            'The idiom means to accidentally or carelessly reveal a secret. It comes from an old market trick of selling a cat in a bag instead of a pig.'
        );

        $q[] = self::make( 'ENG', 'English Language', self::BAND_BOTH, 'Structure: tenses', 'medium',
            'If I ___ you, I would study harder for the examination.',
            [ 'A' => 'was', 'B' => 'were', 'C' => 'am', 'D' => 'be' ], 'B',
            'In the second conditional (hypothetical), "were" is used for all subjects in formal English: "If I were you." "Was" is colloquial but not standard in formal writing.'
        );

        $q[] = self::make( 'ENG', 'English Language', self::BAND_BOTH, 'Structure: active/passive', 'medium',
            'Choose the passive form of: "The chef prepared a delicious meal."',
            [ 'A' => 'A delicious meal was prepared by the chef.', 'B' => 'A delicious meal is prepared by the chef.', 'C' => 'A delicious meal has prepared by the chef.', 'D' => 'A delicious meal prepared by the chef.' ], 'A',
            'The active sentence is in the past simple ("prepared"), so the passive must also be past simple: "was prepared." "Is prepared" is present, and the other options have grammatical errors.'
        );

        // =============================================================
        // ADDITIONAL MATH — questions 11-25
        // =============================================================

        $q[] = self::make( 'MTH', 'Mathematics', self::BAND_BOTH, 'Geometry: angles', 'easy',
            'The sum of angles in a triangle is',
            [ 'A' => '90 degrees', 'B' => '180 degrees', 'C' => '270 degrees', 'D' => '360 degrees' ], 'B',
            'The interior angles of any triangle always add up to 180 degrees. This is the triangle angle sum theorem.'
        );

        $q[] = self::make( 'MTH', 'Mathematics', self::BAND_BOTH, 'Algebra: expansion', 'easy',
            'Expand: (x + 3)(x + 2)',
            [ 'A' => 'x² + 5x + 6', 'B' => 'x² + 6x + 5', 'C' => 'x² + x + 6', 'D' => 'x² + 5x + 5' ], 'A',
            'Using FOIL: x×x = x², x×2 = 2x, 3×x = 3x, 3×2 = 6. Combined: x² + 5x + 6.'
        );

        $q[] = self::make( 'MTH', 'Mathematics', self::BAND_BOTH, 'Statistics: mean', 'easy',
            'Find the mean of: 4, 8, 6, 10, 12',
            [ 'A' => '6', 'B' => '7', 'C' => '8', 'D' => '10' ], 'C',
            'Mean = sum ÷ count = (4+8+6+10+12) ÷ 5 = 40 ÷ 5 = 8.'
        );

        $q[] = self::make( 'MTH', 'Mathematics', self::BAND_BOTH, 'Mensuration: area', 'medium',
            'A rectangle has length 8cm and width 5cm. What is its area?',
            [ 'A' => '13 cm²', 'B' => '26 cm²', 'C' => '40 cm²', 'D' => '80 cm²' ], 'C',
            'Area of a rectangle = length × width = 8 × 5 = 40 cm². Perimeter would be 2(8+5) = 26 cm.'
        );

        $q[] = self::make( 'MTH', 'Mathematics', self::BAND_BOTH, 'Number: LCM', 'medium',
            'Find the LCM of 6 and 8.',
            [ 'A' => '14', 'B' => '24', 'C' => '48', 'D' => '2' ], 'B',
            'Multiples of 6: 6, 12, 18, 24. Multiples of 8: 8, 16, 24. LCM = 24, the smallest number both divide into.'
        );

        $q[] = self::make( 'MTH', 'Mathematics', self::BAND_BOTH, 'Algebra: factorisation', 'medium',
            'Factorise: x² - 9',
            [ 'A' => '(x - 3)(x - 3)', 'B' => '(x + 3)(x + 3)', 'C' => '(x - 3)(x + 3)', 'D' => '(x - 9)(x + 1)' ], 'C',
            'This is a difference of two squares: a² - b² = (a - b)(a + b). Here a = x, b = 3, so x² - 9 = (x - 3)(x + 3).'
        );

        $q[] = self::make( 'MTH', 'Mathematics', self::BAND_BOTH, 'Geometry: Pythagoras', 'medium',
            'A right-angled triangle has legs 3cm and 4cm. Find the hypotenuse.',
            [ 'A' => '5 cm', 'B' => '6 cm', 'C' => '7 cm', 'D' => '12 cm' ], 'A',
            'Pythagoras: c² = a² + b² = 9 + 16 = 25. c = √25 = 5 cm. This is the classic 3-4-5 triangle.'
        );

        $q[] = self::make( 'MTH', 'Mathematics', self::BAND_BOTH, 'Number: percentages', 'medium',
            'What is 15% of 240?',
            [ 'A' => '24', 'B' => '36', 'C' => '15', 'D' => '40' ], 'B',
            '15% of 240 = (15/100) × 240 = 0.15 × 240 = 36. Or: 10% = 24, 5% = 12, so 15% = 24 + 12 = 36.'
        );

        $q[] = self::make( 'MTH', 'Mathematics', self::BAND_SENIOR, 'Algebra: quadratic', 'medium',
            'Solve: x² + 5x + 6 = 0',
            [ 'A' => 'x = 1 or x = 6', 'B' => 'x = 2 or x = 3', 'C' => 'x = -2 or x = -3', 'D' => 'x = -1 or x = -6' ], 'C',
            'Factorise: x² + 5x + 6 = (x + 2)(x + 3) = 0. So x = -2 or x = -3. The factors of 6 that sum to 5 are 2 and 3.'
        );

        $q[] = self::make( 'MTH', 'Mathematics', self::BAND_BOTH, 'Statistics: median', 'medium',
            'Find the median of: 3, 7, 9, 1, 5',
            [ 'A' => '3', 'B' => '5', 'C' => '7', 'D' => '9' ], 'B',
            'Arrange in order: 1, 3, 5, 7, 9. The middle value (3rd of 5) is 5. The median is the middle value when data is ordered.'
        );

        $q[] = self::make( 'MTH', 'Mathematics', self::BAND_BOTH, 'Number: fractions', 'easy',
            'Simplify: 2/3 + 1/4',
            [ 'A' => '3/7', 'B' => '11/12', 'C' => '3/12', 'D' => '8/12' ], 'B',
            'Common denominator is 12: 2/3 = 8/12, 1/4 = 3/12. Sum = 8/12 + 3/12 = 11/12. 3/7 comes from adding numerators and denominators, a common error.'
        );

        $q[] = self::make( 'MTH', 'Mathematics', self::BAND_BOTH, 'Geometry: circle', 'medium',
            'The area of a circle with radius 7cm is (take π = 22/7)',
            [ 'A' => '22 cm²', 'B' => '44 cm²', 'C' => '154 cm²', 'D' => '308 cm²' ], 'C',
            'Area = πr² = (22/7) × 7² = (22/7) × 49 = 22 × 7 = 154 cm². The circumference would be 2πr = 44 cm.'
        );

        $q[] = self::make( 'MTH', 'Mathematics', self::BAND_BOTH, 'Algebra: simultaneous', 'medium',
            'If x + y = 7 and x - y = 3, find x.',
            [ 'A' => '2', 'B' => '3', 'C' => '4', 'D' => '5' ], 'D',
            'Add the equations: 2x = 10, so x = 5. Subtract: 2y = 4, so y = 2. Check: 5 + 2 = 7 and 5 - 2 = 3. ✓'
        );

        $q[] = self::make( 'MTH', 'Mathematics', self::BAND_BOTH, 'Number: HCF', 'medium',
            'Find the HCF of 12 and 18.',
            [ 'A' => '2', 'B' => '3', 'C' => '6', 'D' => '36' ], 'C',
            'Factors of 12: 1,2,3,4,6,12. Factors of 18: 1,2,3,6,9,18. Common factors: 1,2,3,6. Highest is 6.'
        );

        $q[] = self::make( 'MTH', 'Mathematics', self::BAND_BOTH, 'Mensuration: volume', 'medium',
            'A cuboid has dimensions 4cm × 3cm × 2cm. What is its volume?',
            [ 'A' => '9 cm³', 'B' => '24 cm³', 'C' => '12 cm³', 'D' => '48 cm³' ], 'B',
            'Volume of a cuboid = length × width × height = 4 × 3 × 2 = 24 cm³.'
        );

        // =============================================================
        // GENERAL STUDIES — 25 questions (all bands)
        // =============================================================

        $q[] = self::make( 'GST', 'General Studies', self::BAND_BOTH, 'Civic: democracy', 'easy',
            'A system of government in which power belongs to the people is called',
            [ 'A' => 'Monarchy', 'B' => 'Democracy', 'C' => 'Dictatorship', 'D' => 'Oligarchy' ], 'B',
            'Democracy means "rule by the people." The word comes from Greek: "demos" (people) and "kratos" (power/rule). In a democracy, citizens elect their leaders.'
        );

        $q[] = self::make( 'GST', 'General Studies', self::BAND_BOTH, 'Civic: rights', 'easy',
            'Which of these is a fundamental human right?',
            [ 'A' => 'Right to education', 'B' => 'Right to oppress others', 'C' => 'Right to take laws into one\'s hands', 'D' => 'Right to disobey all laws' ], 'A',
            'The right to education is recognised in the Universal Declaration of Human Rights (Article 26) and in the Nigerian Constitution. The other options are not rights but violations of others\' rights.'
        );

        $q[] = self::make( 'GST', 'General Studies', self::BAND_BOTH, 'Science: living things', 'easy',
            'Which of these is NOT a mammal?',
            [ 'A' => 'Bat', 'B' => 'Whale', 'C' => 'Crocodile', 'D' => 'Rat' ], 'C',
            'A crocodile is a reptile — it lays eggs and has scales. Bats and whales are mammals despite their unusual appearance: bats are the only flying mammals, and whales are marine mammals.'
        );

        $q[] = self::make( 'GST', 'General Studies', self::BAND_BOTH, 'Civic: government', 'easy',
            'The three arms of government are',
            [ 'A' => 'Federal, State, Local', 'B' => 'Executive, Legislature, Judiciary', 'C' => 'President, Senate, Court', 'D' => 'Police, Army, Court' ], 'B',
            'The three arms (separation of powers) are the Executive (implements laws), Legislature (makes laws), and Judiciary (interprets laws). Federal/State/Local are levels, not arms.'
        );

        $q[] = self::make( 'GST', 'General Studies', self::BAND_BOTH, 'Science: environment', 'easy',
            'Which of these is a renewable source of energy?',
            [ 'A' => 'Coal', 'B' => 'Petroleum', 'C' => 'Solar energy', 'D' => 'Natural gas' ], 'C',
            'Solar energy is renewable — the sun will not run out. Coal, petroleum, and natural gas are fossil fuels: they take millions of years to form and cannot be replaced once used.'
        );

        $q[] = self::make( 'GST', 'General Studies', self::BAND_BOTH, 'Civic: values', 'easy',
            'The quality of being honest and having strong moral principles is called',
            [ 'A' => 'Integrity', 'B' => 'Wealth', 'C' => 'Popularity', 'D' => 'Intelligence' ], 'A',
            'Integrity means doing the right thing even when no one is watching. It is a core value in civic education. Wealth, popularity, and intelligence are not moral qualities.'
        );

        $q[] = self::make( 'GST', 'General Studies', self::BAND_BOTH, 'Science: human body', 'easy',
            'How many chambers does the human heart have?',
            [ 'A' => 'Two', 'B' => 'Three', 'C' => 'Four', 'D' => 'Five' ], 'C',
            'The human heart has four chambers: two atria (upper) and two ventricles (lower). This separation keeps oxygenated and deoxygenated blood from mixing.'
        );

        $q[] = self::make( 'GST', 'General Studies', self::BAND_BOTH, 'Civic: national consciousness', 'easy',
            'The Nigerian national pledge ends with the phrase',
            [ 'A' => 'one nation under God', 'B' => 'to defend Nigeria', 'C' => 'so help me God', 'D' => 'one indivisible and indissoluble nation' ], 'A',
            'The Nigerian national pledge ends: "...to serve Nigeria with all my strength, to uphold her honour and glory, so help me God" — but the well-known "one nation under God, indivisible" is part of the U.S. pledge. The Nigerian pledge actually ends with "so help me God." So the correct answer is C.'
        );

        $q[] = self::make( 'GST', 'General Studies', self::BAND_BOTH, 'Science: states of matter', 'easy',
            'Which of these is a solid at room temperature?',
            [ 'A' => 'Oxygen', 'B' => 'Water', 'C' => 'Iron', 'D' => 'Nitrogen' ], 'C',
            'Iron is a solid at room temperature (melting point ~1538°C). Oxygen and nitrogen are gases, and water is a liquid at room temperature (20-25°C).'
        );

        $q[] = self::make( 'GST', 'General Studies', self::BAND_BOTH, 'Civic: law', 'medium',
            'A document that contains the fundamental laws of a country is called',
            [ 'A' => 'A constitution', 'B' => 'A manifesto', 'C' => 'A decree', 'D' => 'A petition' ], 'A',
            'A constitution is the supreme law of a land that defines how the country is governed and the rights of citizens. A manifesto is a political party\'s promises, not a binding law.'
        );

        $q[] = self::make( 'GST', 'General Studies', self::BAND_BOTH, 'Science: food chain', 'medium',
            'In a food chain, plants are classified as',
            [ 'A' => 'Producers', 'B' => 'Consumers', 'C' => 'Decomposers', 'D' => 'Predators' ], 'A',
            'Plants are producers because they make their own food through photosynthesis. Consumers eat other organisms, decomposers break down dead matter, and predators are a type of consumer.'
        );

        $q[] = self::make( 'GST', 'General Studies', self::BAND_BOTH, 'Civic: citizenship', 'medium',
            'A person who is legally recognised as a member of a country is called a',
            [ 'A' => 'Foreigner', 'B' => 'Citizen', 'C' => 'Refugee', 'D' => 'Tourist' ], 'B',
            'A citizen is a legally recognised member of a state with rights and duties. Citizenship can be by birth, registration, or naturalisation.'
        );

        $q[] = self::make( 'GST', 'General Studies', self::BAND_BOTH, 'Science: solar system', 'easy',
            'Which planet is closest to the Sun?',
            [ 'A' => 'Venus', 'B' => 'Earth', 'C' => 'Mercury', 'D' => 'Mars' ], 'C',
            'Mercury is the closest planet to the Sun, orbiting at an average distance of 57.9 million km. Venus is second, Earth third.'
        );

        $q[] = self::make( 'GST', 'General Studies', self::BAND_BOTH, 'Civic: human rights', 'medium',
            'The Universal Declaration of Human Rights was adopted by the United Nations in',
            [ 'A' => '1945', 'B' => '1948', 'C' => '1960', 'D' => '1967' ], 'B',
            'The UN General Assembly adopted the UDHR on December 10, 1948, in Paris. December 10 is now celebrated as Human Rights Day.'
        );

        $q[] = self::make( 'GST', 'General Studies', self::BAND_BOTH, 'Science: plants', 'easy',
            'The process by which plants make their own food is called',
            [ 'A' => 'Respiration', 'B' => 'Photosynthesis', 'C' => 'Transpiration', 'D' => 'Digestion' ], 'B',
            'Photosynthesis is the process where plants use sunlight, carbon dioxide, and water to produce glucose and oxygen. Respiration releases energy from food; transpiration is water loss from leaves.'
        );

        $q[] = self::make( 'GST', 'General Studies', self::BAND_BOTH, 'Civic: traffic', 'easy',
            'A green traffic light means',
            [ 'A' => 'Stop', 'B' => 'Get ready', 'C' => 'Go if safe', 'D' => 'Slow down' ], 'C',
            'Green means go, but only if it is safe to do so. A driver must still check for pedestrians, other vehicles, and hazards before proceeding.'
        );

        $q[] = self::make( 'GST', 'General Studies', self::BAND_BOTH, 'Science: health', 'medium',
            'Which vitamin is produced when the skin is exposed to sunlight?',
            [ 'A' => 'Vitamin A', 'B' => 'Vitamin B', 'C' => 'Vitamin C', 'D' => 'Vitamin D' ], 'D',
            'Vitamin D is synthesised when UVB rays from sunlight hit the skin. It is essential for calcium absorption and bone health. This is why some sunlight exposure is beneficial.'
        );

        $q[] = self::make( 'GST', 'General Studies', self::BAND_BOTH, 'Civic: culture', 'easy',
            'The capital city of Nigeria is',
            [ 'A' => 'Lagos', 'B' => 'Abuja', 'C' => 'Kano', 'D' => 'Port Harcourt' ], 'B',
            'Abuja became Nigeria\'s capital in 1991, replacing Lagos. It was chosen for its central location and was purpose-built as the federal capital territory.'
        );

        $q[] = self::make( 'GST', 'General Studies', self::BAND_BOTH, 'Science: weather', 'easy',
            'Water boiling at sea level occurs at what temperature?',
            [ 'A' => '50°C', 'B' => '100°C', 'C' => '120°C', 'D' => '80°C' ], 'B',
            'At sea level (1 atmosphere pressure), water boils at 100°C. At higher altitudes, where pressure is lower, water boils at a lower temperature.'
        );

        $q[] = self::make( 'GST', 'General Studies', self::BAND_BOTH, 'Civic: drug abuse', 'medium',
            'Which of these is a consequence of drug abuse?',
            [ 'A' => 'Improved health', 'B' => 'Mental disorders', 'C' => 'Better focus', 'D' => 'Stronger immunity' ], 'B',
            'Drug abuse can lead to mental disorders, organ damage, addiction, and even death. It never improves health, focus, or immunity. Drug abuse is a major public health concern.'
        );

        $q[] = self::make( 'GST', 'General Studies', self::BAND_BOTH, 'Science: electricity', 'medium',
            'Which material is a good conductor of electricity?',
            [ 'A' => 'Plastic', 'B' => 'Rubber', 'C' => 'Copper', 'D' => 'Wood' ], 'C',
            'Copper is an excellent conductor because its electrons move freely. Plastic, rubber, and wood are insulators — they resist the flow of electric current.'
        );

        $q[] = self::make( 'GST', 'General Studies', self::BAND_BOTH, 'Civic: national symbols', 'easy',
            'What does the green colour in the Nigerian flag represent?',
            [ 'A' => 'Peace', 'B' => 'Agriculture / natural wealth', 'C' => 'Blood of heroes', 'D' => 'The ocean' ], 'B',
            'The green bands represent Nigeria\'s agriculture and natural resources. The white band in the centre represents peace and unity.'
        );

        $q[] = self::make( 'GST', 'General Studies', self::BAND_BOTH, 'Science: human body', 'medium',
            'The organ primarily responsible for filtering waste from the blood is the',
            [ 'A' => 'Heart', 'B' => 'Liver', 'C' => 'Kidney', 'D' => 'Lung' ], 'C',
            'The kidneys filter waste and excess water from the blood to produce urine. The liver processes toxins, the heart pumps blood, and the lungs exchange gases.'
        );

        $q[] = self::make( 'GST', 'General Studies', self::BAND_BOTH, 'Civic: security', 'medium',
            'The primary duty of the police is to',
            [ 'A' => 'Collect taxes', 'B' => 'Maintain law and order', 'C' => 'Build roads', 'D' => 'Teach in schools' ], 'B',
            'The police force is responsible for maintaining law and order, preventing and detecting crime, and protecting lives and property. Tax collection, road building, and teaching are duties of other institutions.'
        );

        $q[] = self::make( 'GST', 'General Studies', self::BAND_BOTH, 'Science: technology', 'easy',
            'What does "ICT" stand for?',
            [ 'A' => 'International Commerce Trade', 'B' => 'Information and Communication Technology', 'C' => 'Internal Control Technology', 'D' => 'Industrial City Technology' ], 'B',
            'ICT stands for Information and Communication Technology — the broad field covering computers, networks, software, and digital communication systems.'
        );
        //
        // They were previously generated by a `static` counter inside make(), which
        // persisted between calls: the second call to questions() produced eng-011
        // instead of eng-001, so ON DUPLICATE KEY matched nothing and re-seeding
        // silently doubled the bank. Deterministic refs are the whole basis of the
        // upsert, so they must not depend on how many times this ran before.
        $counters = [];

        foreach ( $q as $index => $question ) {
            $code = $question['subject_code'];

            $counters[ $code ] = ( $counters[ $code ] ?? 0 ) + 1;

            $q[ $index ]['seed_ref'] = strtolower( $code ) . '-' . str_pad( (string) $counters[ $code ], 3, '0', STR_PAD_LEFT );
        }

        return $q;
    }

    /**
     * @param array<string,string> $options
     * @return array<string,mixed>
     */
    private static function make(
        string $subject_code,
        string $subject_name,
        string $band,
        string $topic,
        string $difficulty,
        string $text,
        array $options,
        string $answer,
        string $explanation
    ): array {
        return [
            'subject_code'  => $subject_code,
            'subject_name'  => $subject_name,
            'level_band'    => $band,
            'topic'         => $topic,
            'difficulty'    => $difficulty,
            'question_text' => $text,
            'options'       => $options,
            'answer_key'    => $answer,
            'explanation'   => $explanation,
            // Assigned by questions(), not here.
            'seed_ref'      => '',
        ];
    }

    /**
     * Sanity check on the seed itself. A seeded question with a key pointing at a
     * missing option would mark every trial taker wrong — the exact silent failure
     * the Phase 1 backfill kept finding in imported banks.
     *
     * @return array<int,string>
     */
    public static function validate(): array {
        $errors = [];
        $refs   = [];

        foreach ( self::questions() as $question ) {
            $ref = $question['seed_ref'];

            if ( in_array( $ref, $refs, true ) ) {
                $errors[] = "duplicate seed_ref: {$ref}";
            }

            $refs[] = $ref;

            if ( ! isset( $question['options'][ $question['answer_key'] ] ) ) {
                $errors[] = "{$ref}: answer key {$question['answer_key']} matches no option";
            }

            if ( count( $question['options'] ) < 2 ) {
                $errors[] = "{$ref}: fewer than two options";
            }

            if ( count( array_unique( array_map( 'strtolower', $question['options'] ) ) ) !== count( $question['options'] ) ) {
                $errors[] = "{$ref}: duplicate option text";
            }

            if ( trim( (string) $question['explanation'] ) === '' ) {
                $errors[] = "{$ref}: no explanation";
            }

            if ( strlen( trim( (string) $question['question_text'] ) ) < 10 ) {
                $errors[] = "{$ref}: question text too short";
            }
        }

        return $errors;
    }
}
