<?php
/**
 * Quality Lab — evidence-based SEO, AIO and GEO evaluation.
 *
 * What this class does:
 *   - Runs every applicable criterion against facts extracted by
 *     AvandWP_Content_Analyzer.
 *   - Produces three INDEPENDENT scores, each normalised over only the criteria
 *     that were actually evaluable for that profile.
 *   - Reports every criterion with its weight, observed evidence, severity,
 *     explanation, recommendation and whether a safe automatic fix exists.
 *
 * What this class explicitly does NOT do:
 *   - It does not ask a model to grade its own output.
 *   - It does not treat missing evidence as a pass. An unavailable criterion is
 *     excluded from the denominator and listed in `unavailable`.
 *   - It does not present a score as a ranking or citation guarantee.
 *
 * @package AvandWP\Quality
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Scoring engine.
 */
class AvandWP_Quality_Lab {

	/**
	 * Partial credit awarded to a criterion scored as `partial`.
	 */
	const PARTIAL_CREDIT = 0.5;

	/**
	 * Evaluate content and return per-profile scores plus full findings.
	 *
	 * @param string $html    Article body HTML.
	 * @param array  $context Context for the analyzer, plus optional brief keys:
	 *                        length_target (int), length_mode (words|characters),
	 *                        length_include_spaces (bool), has_schema (bool|null),
	 *                        updated_date (string).
	 * @return array{
	 *   scores: array<string, int|null>,
	 *   profiles: array<string, array>,
	 *   findings: array<int, array>,
	 *   facts: array,
	 *   summary: array
	 * }
	 */
	public static function evaluate( $html, array $context = array() ) {
		$facts = AvandWP_Content_Analyzer::analyze( $html, $context );

		// Merge brief-level values that criteria need but the analyzer does not
		// derive from the HTML itself.
		$facts['length_target']         = (int) ( $context['length_target'] ?? 0 );
		$facts['length_mode']           = (string) ( $context['length_mode'] ?? 'words' );
		$facts['length_include_spaces'] = (bool) ( $context['length_include_spaces'] ?? true );
		$facts['updated_date']          = $context['updated_date'] ?? null;

		$profiles = array();
		$findings = array();

		foreach ( array_keys( AvandWP_Criteria::profiles() ) as $profile ) {
			$profiles[ $profile ] = self::evaluate_profile( $profile, $facts );
		}

		foreach ( $profiles as $profile => $result ) {
			foreach ( $result['criteria'] as $criterion ) {
				$findings[] = $criterion;
			}
		}

		// Order findings by severity then by profile weight so the report leads
		// with what actually matters.
		$order = array( 'critical' => 0, 'high' => 1, 'medium' => 2, 'low' => 3 );
		usort(
			$findings,
			static function ( $a, $b ) use ( $order ) {
				$sa = $order[ $a['severity'] ] ?? 9;
				$sb = $order[ $b['severity'] ] ?? 9;

				// Failures first, then partials, then passes and unavailable.
				$ra = array( 'fail' => 0, 'partial' => 1, 'unavailable' => 2, 'pass' => 3 )[ $a['result'] ] ?? 9;
				$rb = array( 'fail' => 0, 'partial' => 1, 'unavailable' => 2, 'pass' => 3 )[ $b['result'] ] ?? 9;

				if ( $ra !== $rb ) {
					return $ra <=> $rb;
				}
				if ( $sa !== $sb ) {
					return $sa <=> $sb;
				}
				return $b['weight'] <=> $a['weight'];
			}
		);

		return array(
			'scores'   => array(
				'seo' => $profiles['seo']['score'],
				'aio' => $profiles['aio']['score'],
				'geo' => $profiles['geo']['score'],
			),
			'profiles' => $profiles,
			'findings' => $findings,
			'facts'    => $facts,
			'summary'  => self::summarise( $profiles, $findings, $facts ),
		);
	}

	/**
	 * Evaluate one profile.
	 *
	 * @param string $profile Profile key.
	 * @param array  $facts   Analyzer facts.
	 * @return array
	 */
	private static function evaluate_profile( $profile, array $facts ) {
		$criteria = AvandWP_Criteria::for_profile( $profile );

		$earned      = 0.0;
		$denominator = 0;
		$results     = array();
		$unavailable = array();
		$counts      = array( 'pass' => 0, 'partial' => 0, 'fail' => 0, 'unavailable' => 0 );

		foreach ( $criteria as $id => $criterion ) {
			$evaluation = array(
				'result'         => AvandWP_Criteria::UNAVAILABLE,
				'evidence'       => '',
				'explanation'    => 'این معیار ارزیابی نشد.',
				'recommendation' => '',
			);

			try {
				$returned = call_user_func( $criterion['evaluate'], $facts );
				if ( is_array( $returned ) ) {
					$evaluation = array_merge( $evaluation, $returned );
				}
			} catch ( Throwable $e ) {
				// A broken criterion must never take down the report; surface it
				// as unavailable with the reason instead of guessing a result.
				$evaluation['result']      = AvandWP_Criteria::UNAVAILABLE;
				$evaluation['evidence']    = 'خطا در ارزیابی این معیار.';
				$evaluation['explanation'] = $e->getMessage();
			}

			$counts[ $evaluation['result'] ] = ( $counts[ $evaluation['result'] ] ?? 0 ) + 1;

			if ( AvandWP_Criteria::UNAVAILABLE === $evaluation['result'] ) {
				$unavailable[] = $id;
			} else {
				$denominator += (int) $criterion['weight'];

				if ( AvandWP_Criteria::PASS === $evaluation['result'] ) {
					$earned += (float) $criterion['weight'];
				} elseif ( AvandWP_Criteria::PARTIAL === $evaluation['result'] ) {
					$earned += ( (float) $criterion['weight'] ) * self::PARTIAL_CREDIT;
				}
			}

			$results[] = array(
				'id'             => $id,
				'profile'        => $profile,
				'label'          => $criterion['label'],
				'measures'       => $criterion['measures'],
				'weight'         => (int) $criterion['weight'],
				'severity'       => $criterion['severity'],
				'auto_fixable'   => (bool) $criterion['auto_fixable'],
				'result'         => $evaluation['result'],
				'evidence'       => (string) $evaluation['evidence'],
				'explanation'    => (string) $evaluation['explanation'],
				'recommendation' => (string) $evaluation['recommendation'],
			);
		}

		// A profile with no evaluable criteria has NO score. Returning 0 would be
		// a fabricated failure and returning 100 a fabricated pass.
		$score = $denominator > 0
			? (int) round( ( $earned / $denominator ) * 100 )
			: null;

		if ( $denominator > 0 ) {
			// Keep the arithmetic honest: a score of 100 requires every applicable
			// criterion to have passed outright.
			$score = min( 100, max( 0, $score ) );
			if ( 100 === $score && $counts['partial'] > 0 ) {
				$score = 99;
			}
		}

		return array(
			'profile'              => $profile,
			'meta'                 => AvandWP_Criteria::profiles()[ $profile ],
			'score'                => $score,
			'earned_weight'        => round( $earned, 2 ),
			'possible_weight'      => $denominator,
			'counts'               => $counts,
			'unavailable_criteria' => $unavailable,
			'criteria'             => $results,
		);
	}

	/**
	 * Build the human-facing summary: strengths, weaknesses, blockers, next step.
	 *
	 * @param array $profiles Per-profile results.
	 * @param array $findings Flat findings list.
	 * @param array $facts    Analyzer facts.
	 * @return array
	 */
	private static function summarise( array $profiles, array $findings, array $facts ) {
		$strengths   = array();
		$weaknesses  = array();
		$blockers    = array();
		$high        = array();
		$low         = array();
		$auto_fixable = array();

		foreach ( $findings as $finding ) {
			switch ( $finding['result'] ) {
				case AvandWP_Criteria::PASS:
					if ( $finding['weight'] >= 5 ) {
						$strengths[] = $finding['label'];
					}
					break;

				case AvandWP_Criteria::PARTIAL:
				case AvandWP_Criteria::FAIL:
					$entry = array(
						'id'             => $finding['id'],
						'profile'        => $finding['profile'],
						'label'          => $finding['label'],
						'severity'       => $finding['severity'],
						'result'         => $finding['result'],
						'evidence'       => $finding['evidence'],
						'recommendation' => $finding['recommendation'],
						'auto_fixable'   => $finding['auto_fixable'],
					);

					if ( AvandWP_Criteria::FAIL === $finding['result'] && in_array( $finding['severity'], array( 'critical', 'high' ), true ) ) {
						$blockers[] = $entry;
					} elseif ( in_array( $finding['severity'], array( 'critical', 'high' ), true ) ) {
						$high[] = $entry;
					} elseif ( 'medium' === $finding['severity'] ) {
						$weaknesses[] = $entry;
					} else {
						$low[] = $entry;
					}

					if ( $finding['auto_fixable'] && AvandWP_Criteria::FAIL !== $finding['result'] ) {
						$auto_fixable[] = $finding['id'];
					}
					break;
			}
		}

		$critical_count = count( $blockers );
		$scores         = array(
			'seo' => $profiles['seo']['score'],
			'aio' => $profiles['aio']['score'],
			'geo' => $profiles['geo']['score'],
		);

		$evaluated = array_filter(
			$scores,
			static function ( $value ) {
				return null !== $value;
			}
		);

		if ( empty( $evaluated ) ) {
			$overall = null;
			$status  = 'ارزیابی ممکن نشد';
			$next    = 'داده کافی برای ارزیابی وجود ندارد. بریف مقاله (کلمه کلیدی، هدف طول، نهادها) را تکمیل کنید.';
		} else {
			$overall = (int) round( array_sum( $evaluated ) / count( $evaluated ) );

			if ( $critical_count > 0 ) {
				$status = 'نیازمند اصلاح (موارد بازدارنده)';
				$next   = sprintf( 'ابتدا %d مورد بازدارنده را برطرف کنید: %s', $critical_count, $blockers[0]['label'] );
			} elseif ( $overall >= 80 ) {
				$status = 'آماده انتشار';
				$next   = 'هیچ بازدارنده‌ای باقی نمانده است. موارد با اولویت متوسط را در صورت تمایل بهبود دهید.';
			} elseif ( $overall >= 60 ) {
				$status = 'قابل قبول، نیازمند بهبود';
				$next   = 'محتوا منتشرشدنی است اما بهبود موارد با اولویت بالا اثر معناداری دارد.';
			} else {
				$status = 'نیازمند بازنگری جدی';
				$next   = 'محتوا پیش از انتشار به بازنگری ساختاری نیاز دارد.';
			}
		}

		$unavailable_total = 0;
		foreach ( $profiles as $profile ) {
			$unavailable_total += count( $profile['unavailable_criteria'] );
		}

		return array(
			'scores'               => $scores,
			'overall'              => $overall,
			'status'               => $status,
			'next_action'          => $next,
			'strengths'            => array_values( array_unique( $strengths ) ),
			'blockers'             => $blockers,
			'high_priority'        => $high,
			'weaknesses'           => array_values( $weaknesses ),
			'low_priority'         => array_values( $low ),
			'auto_fixable_ids'     => array_values( array_unique( $auto_fixable ) ),
			'unavailable_criteria' => $unavailable_total,
			'measured_length'      => array(
				'words'                => (int) $facts['measurement']['words'],
				'chars_with_spaces'    => (int) $facts['measurement']['chars_with_spaces'],
				'chars_without_spaces' => (int) $facts['measurement']['chars_without_spaces'],
				'reading_minutes'      => (float) $facts['measurement']['reading_minutes'],
			),
			'disclaimer'           => 'امتیاز ۱۰۰ تنها به این معناست که همه معیارهای قابل ارزیابیِ تعریف‌شده در این افزونه درست بوده‌اند. این عدد تضمین رتبه در موتورهای جستجو یا استناد توسط سیستم‌های هوش مصنوعی نیست.',
		);
	}

	/**
	 * Re-evaluate one or more criteria against the same facts, for the
	 * "re-run affected checks after a correction" workflow.
	 *
	 * @param array  $facts       Facts from a previous evaluate() call.
	 * @param array  $criterion_ids Criterion ids to re-run. Empty means all.
	 * @return array<int, array> Updated findings for the requested criteria.
	 */
	public static function recheck( array $facts, array $criterion_ids = array() ) {
		$out      = array();
		$registry = AvandWP_Criteria::all();

		foreach ( $registry as $id => $criterion ) {
			if ( ! empty( $criterion_ids ) && ! in_array( $id, $criterion_ids, true ) ) {
				continue;
			}

			$evaluation = array(
				'result'         => AvandWP_Criteria::UNAVAILABLE,
				'evidence'       => '',
				'explanation'    => '',
				'recommendation' => '',
			);

			try {
				$returned = call_user_func( $criterion['evaluate'], $facts );
				if ( is_array( $returned ) ) {
					$evaluation = array_merge( $evaluation, $returned );
				}
			} catch ( Throwable $e ) {
				$evaluation['explanation'] = $e->getMessage();
			}

			$out[] = array(
				'id'             => $id,
				'profile'        => $criterion['profile'],
				'label'          => $criterion['label'],
				'weight'         => (int) $criterion['weight'],
				'severity'       => $criterion['severity'],
				'auto_fixable'   => (bool) $criterion['auto_fixable'],
				'result'         => $evaluation['result'],
				'evidence'       => (string) $evaluation['evidence'],
				'explanation'    => (string) $evaluation['explanation'],
				'recommendation' => (string) $evaluation['recommendation'],
			);
		}

		return $out;
	}

	/**
	 * Flat list of criteria for the documentation/UI table.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function describe_all() {
		$out = array();

		foreach ( AvandWP_Criteria::all() as $id => $criterion ) {
			$out[] = array(
				'id'           => $id,
				'profile'      => $criterion['profile'],
				'label'        => $criterion['label'],
				'weight'       => (int) $criterion['weight'],
				'severity'     => $criterion['severity'],
				'auto_fixable' => (bool) $criterion['auto_fixable'],
				'measures'     => $criterion['measures'],
			);
		}

		return $out;
	}
}
