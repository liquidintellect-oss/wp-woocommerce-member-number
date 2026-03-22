<?php
/**
 * Number format template logic: generation, regex conversion, validation.
 *
 * @package WooCommerce_Member_Number
 */

defined( 'ABSPATH' ) || exit;

/**
 * Handles all number format template logic: generation, regex conversion, validation.
 */
class WMN_Number_Formatter {

	/**
	 * The format template string.
	 *
	 * @var string
	 */
	private string $template;

	/**
	 * The prefix used for the {PREFIX} token.
	 *
	 * @var string
	 */
	private string $prefix;

	/**
	 * Minimum digit width for zero-padded {SEQ}.
	 *
	 * @var int
	 */
	private int $pad_length;

	/**
	 * Minimum allowed sequence value.
	 *
	 * @var int
	 */
	private int $min_value;

	/**
	 * Maximum allowed sequence value.
	 *
	 * @var int
	 */
	private int $max_value;

	/**
	 * Constructor.
	 *
	 * @param string $template   Format template string.
	 * @param string $prefix     Prefix token value.
	 * @param int    $pad_length Zero-pad width for {SEQ}.
	 * @param int    $min_value  Minimum sequence value.
	 * @param int    $max_value  Maximum sequence value.
	 */
	public function __construct(
		string $template = '{PREFIX}{SEQ}',
		string $prefix = 'MBR-',
		int $pad_length = 6,
		int $min_value = 1,
		int $max_value = 999999
	) {
		$this->template   = $template;
		$this->prefix     = $prefix;
		$this->pad_length = max( 1, $pad_length );
		$this->min_value  = max( 0, $min_value );
		$this->max_value  = max( $this->min_value, $max_value );
	}

	/**
	 * Render the format template, substituting all tokens.
	 *
	 * @param int $sequence The current sequence counter value.
	 * @return string
	 */
	public function generate( int $sequence ): string {
		$map = array(
			'{PREFIX}' => $this->prefix,
			'{SEQ}'    => str_pad( (string) $sequence, $this->pad_length, '0', STR_PAD_LEFT ),
			'{YEAR}'   => gmdate( 'Y' ),
			'{MONTH}'  => gmdate( 'm' ),
			'{RAND4}'  => strtoupper( wp_generate_password( 4, false, false ) ),
			'{RAND6}'  => strtoupper( wp_generate_password( 6, false, false ) ),
		);
		return strtr( $this->template, $map );
	}

	/**
	 * Convert the format template to a PHP regex for structural validation.
	 * Only works deterministically when the template has no {RAND} tokens.
	 *
	 * @return string  Full regex including delimiters, e.g.  /^MBR\-\d{6}$/
	 */
	public function to_regex(): string {
		// Replace each token with its regex equivalent.
		$pattern = preg_quote( $this->template, '/' );

		// After preg_quote the tokens become e.g. \{PREFIX\} — undo that.
		$pattern = str_replace(
			array( '\\{PREFIX\\}', '\\{SEQ\\}', '\\{YEAR\\}', '\\{MONTH\\}', '\\{RAND4\\}', '\\{RAND6\\}' ),
			array(
				preg_quote( $this->prefix, '/' ),
				'\d{' . $this->pad_length . ',}',
				'\d{4}',
				'\d{2}',
				'[A-Z0-9]{4}',
				'[A-Z0-9]{6}',
			),
			$pattern
		);

		return '/^' . $pattern . '$/i';
	}

	/**
	 * Validate a user-submitted chosen number.
	 *
	 * @param string $input Raw user input.
	 * @return true|WP_Error
	 */
	public function validate_chosen( string $input ) {
		$input = trim( $input );

		if ( '' === $input ) {
			return new WP_Error( 'wmn_empty', __( 'Please enter a number.', 'wmn' ) );
		}

		// Structural check.
		$regex = $this->to_regex();
		if ( ! preg_match( $regex, $input ) ) {
			return new WP_Error(
				'wmn_invalid_format',
				sprintf(
					/* translators: %s: example number */
					__( 'Invalid format. Example: %s', 'wmn' ),
					$this->generate( $this->min_value > 0 ? $this->min_value : 1 )
				)
			);
		}

		// Range check — only applicable when template contains {SEQ}.
		if ( false !== strpos( $this->template, '{SEQ}' ) ) {
			// Extract the numeric portion.
			$seq = $this->extract_sequence( $input );
			if ( null !== $seq ) {
				if ( $seq < $this->min_value ) {
					return new WP_Error(
						'wmn_below_min',
						sprintf(
							/* translators: %d: minimum value */
							__( 'Number must be at least %d.', 'wmn' ),
							$this->min_value
						)
					);
				}
				if ( $seq > $this->max_value ) {
					return new WP_Error(
						'wmn_above_max',
						sprintf(
							/* translators: %d: maximum value */
							__( 'Number must not exceed %d.', 'wmn' ),
							$this->max_value
						)
					);
				}
			}
		}

		// Allow external validation.
		$errors = new WP_Error();
		do_action( 'wmn_validate_chosen_number', $errors, $input );
		if ( $errors->has_errors() ) {
			return $errors;
		}

		return true;
	}

	/**
	 * Returns true if the number structurally matches the format template.
	 *
	 * @param string $number The number to check.
	 * @return bool
	 */
	public function is_in_pool( string $number ): bool {
		return (bool) preg_match( $this->to_regex(), $number );
	}

	/**
	 * Normalize raw user input into a fully-formatted member number.
	 *
	 * Customers are only required to enter the numeric portion. This method
	 * applies the prefix and zero-padding (number mask) automatically so that
	 * downstream validation always receives a fully-formatted string.
	 *
	 * @param string $input Raw user input.
	 * @return string
	 */
	public function normalize_input( string $input ): string {
		$input = trim( $input );

		// If the customer entered only digits, treat the value as the sequence
		// number and generate the full formatted member number (prefix + padding).
		if ( ctype_digit( $input ) && false !== strpos( $this->template, '{SEQ}' ) ) {
			return $this->generate( (int) $input );
		}

		// Legacy fallback: auto-prefix if the template starts with {PREFIX} and
		// the prefix is not already present.
		if (
			'' !== $this->prefix &&
			str_starts_with( $this->template, '{PREFIX}' ) &&
			! str_starts_with( strtolower( $input ), strtolower( $this->prefix ) )
		) {
			$input = $this->prefix . $input;
		}

		return $input;
	}

	/**
	 * Extract the sequence integer from a formatted number string.
	 * Returns null if the template does not contain {SEQ}.
	 *
	 * @param string $number A formatted member number string.
	 * @return int|null
	 */
	public function extract_sequence( string $number ): ?int {
		if ( false === strpos( $this->template, '{SEQ}' ) ) {
			return null;
		}

		// Build a capture regex — replace {SEQ} token with a capture group.
		$capture = $this->to_regex();
		// Replace the \d{N,} part with a capture group.
		$capture = preg_replace( '/\\\\d\{[^}]+\}/', '(\d+)', $capture );
		if ( preg_match( $capture, $number, $m ) ) {
			return isset( $m[1] ) ? (int) $m[1] : null;
		}
		return null;
	}

	// ── Getters ──────────────────────────────────────────────────────────────

	/**
	 * Returns the format template string.
	 *
	 * @return string
	 */
	public function get_template(): string {
		return $this->template; }

	/**
	 * Returns the prefix value.
	 *
	 * @return string
	 */
	public function get_prefix(): string {
		return $this->prefix; }

	/**
	 * Returns the sequence pad length.
	 *
	 * @return int
	 */
	public function get_pad_length(): int {
		return $this->pad_length; }

	/**
	 * Returns the minimum sequence value.
	 *
	 * @return int
	 */
	public function get_min_value(): int {
		return $this->min_value; }

	/**
	 * Returns the maximum sequence value.
	 *
	 * @return int
	 */
	public function get_max_value(): int {
		return $this->max_value; }
}
