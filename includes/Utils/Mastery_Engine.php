<?php
namespace QuestionPress\Utils;

/**
 * The mathematical engine for calculating user mastery using IRT/Elo,
 * Event-Sourcing, Anti-Binge Asymptotes, and Ebbinghaus Decay.
 */
class Mastery_Engine {
    
    // === IRT / ELO CONSTANTS ===
    const ELO_K_FACTOR = 32;   // Maximum capability points gained/lost per attempt
    const ELO_SCALE = 400;     // Standard Elo scale factor
    const BASE_START_ELO = 400;// Starting capability for a brand new subject

    // === ANTI-BINGE CONSTANTS ===
    // 5% reduction in point value per consecutive question asked TODAY
    const BINGE_DECAY_RATE = 0.05; 

    // === TIME DECAY CONSTANTS ===
    const BASE_DAILY_DECAY = 0.03; // 3% loss per day of inactivity
    const MAX_MOMENTUM = 0.33;     // Streak max: drops the decay rate down to 1% (0.03 * 0.33)
    
    // === BREADTH QUOTA ===
    const BREADTH_QUOTA = 150;     // Distinct questions needed to achieve a 100% breadth multiplier

    public static function process_attempts($current_state, $attempts) {
        $state = $current_state;

        if (empty($state['current_session_date'])) {
            $state['mastery_depth'] = self::BASE_START_ELO;
            $state['distinct_questions'] = 0;
            $state['momentum_factor'] = 1.0;
            $state['today_attempts_count'] = 0;
            $state['today_accumulated_delta'] = 0.0;
            $state['current_session_date'] = null;
            $state['last_active_date'] = null; // NEW CLOCK
            $state['last_change'] = 0.0; 
        }

        if (!isset($state['total_answered'])) $state['total_answered'] = 0;
        if (!isset($state['correct_count'])) $state['correct_count'] = 0;

        $score_at_last_known_eod = isset($state['mastery_level']) ? (float)$state['mastery_level'] : 0.0;

        foreach ($attempts as $attempt) {
            $attempt_date = date('Y-m-d', strtotime($attempt->attempt_time));

            if ($state['current_session_date'] !== null && $state['current_session_date'] !== $attempt_date) {
                $score_at_last_known_eod = self::calculate_final_score($state);
                self::flush_and_decay($state, $attempt_date);
            }

            if ($state['current_session_date'] === null) {
                $state['current_session_date'] = $attempt_date;
            }
            
            // NEW: Track exactly when they actually answered a question
            $state['last_active_date'] = $attempt_date;

            $is_correct = (int)$attempt->is_correct;
            $state['total_answered']++;
            $state['correct_count'] += $is_correct;

            $q_hardness = isset($attempt->question_hardness) ? (float)$attempt->question_hardness : 500.0;
            $expected_score = 1 / (1 + pow(10, ($q_hardness - $state['mastery_depth']) / self::ELO_SCALE));
            $raw_delta = self::ELO_K_FACTOR * ($is_correct - $expected_score);

            $binge_multiplier = exp(-self::BINGE_DECAY_RATE * $state['today_attempts_count']);
            $diminished_delta = $raw_delta * $binge_multiplier;

            if (!empty($attempt->behavioral_metrics)) {
                $diminished_delta *= self::calculate_behavioral_multiplier($attempt->behavioral_metrics);
            }

            $state['today_accumulated_delta'] += $diminished_delta;
            $state['today_attempts_count']++;

            if (isset($attempt->is_first_attempt) && $attempt->is_first_attempt) {
                $state['distinct_questions']++;
            }
        }

        $state['mastery_level'] = self::calculate_final_score($state);
        
        if (!empty($attempts)) {
            $state['last_change'] = $state['mastery_level'] - $score_at_last_known_eod;
        }

        return $state;
    }

    private static function flush_and_decay(&$state, $new_date) {
        $state['mastery_depth'] += $state['today_accumulated_delta'];
        
        // --- DECOUPLED CLOCKS ---
        
        // 1. Check The Human Clock (For Streaks)
        // If they don't have a last_active_date, fall back to current_session
        $human_date = $state['last_active_date'] ?? $state['current_session_date'];
        $days_since_active = (new \DateTime($human_date))->diff(new \DateTime($new_date))->days;
        
        // 2. Check The Math Clock (For Decay)
        $days_since_calc = (new \DateTime($state['current_session_date']))->diff(new \DateTime($new_date))->days;

        if ($days_since_calc > 0) {
            $actual_decay_rate = self::BASE_DAILY_DECAY * $state['momentum_factor'];
            $decay_multiplier = pow((1 - $actual_decay_rate), $days_since_calc);
            $state['mastery_depth'] = max(0, $state['mastery_depth'] * $decay_multiplier);

            // STREAK LOGIC now strictly relies on when they actually touched the system!
            if ($days_since_active == 1) {
                $state['momentum_factor'] = max(self::MAX_MOMENTUM, $state['momentum_factor'] - 0.05);
            } else {
                $state['momentum_factor'] = 1.0;
            }
        }

        $state['today_accumulated_delta'] = 0.0;
        $state['today_attempts_count'] = 0;
        $state['current_session_date'] = $new_date;
    }

    /**
     * Combines Depth (Elo) and Breadth (Coverage) into the final 0-100 percentage.
     */
    private static function calculate_final_score($state) {
        $current_depth = $state['mastery_depth'] + $state['today_accumulated_delta'];
        
        // Let's assume an Elo of 1000 represents 100% capability.
        $depth_percentage = min(100, max(0, ($current_depth / 1000) * 100));

        // Breadth uses a square root curve so that the first 50 questions matter
        // much more than the final 50 questions toward the quota.
        $breadth_ratio = min(1.0, $state['distinct_questions'] / self::BREADTH_QUOTA);
        $breadth_multiplier = sqrt($breadth_ratio);

        // Final score calculation
        return round($depth_percentage * $breadth_multiplier, 2);
    }

    /**
     * A placeholder to decode behavioral JSON and penalize the delta if they guessed or struggled.
     */
    private static function calculate_behavioral_multiplier($json_metrics) {
        // TODO: Implement this function to parse the JSON and return a multiplier between 0.5 and 1.0 based on the presence of negative behaviors.
        // You will implement this later when React starts sending telemetry.
        // Returns a value between 0.0 and 1.0. 
        return 1.0; 
    }
}