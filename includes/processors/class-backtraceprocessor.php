<?php declare(strict_types=1);
/**
 * Backtraces records processing
 *
 * Adds backtrace specific record.
 *
 * @package Processors
 * @author  Pierre Lannoy <https://pierre.lannoy.fr/>.
 * @since   1.0.0
 */

namespace Decalog\Processor;

use Monolog\Logger;
use Monolog\Processor\ProcessorInterface;

/**
 * Define the backtrace processor functionality.
 *
 * Adds backtrace specific record.
 *
 * @package Processors
 * @author  Pierre Lannoy <https://pierre.lannoy.fr/>.
 * @since   1.0.0
 */
class BacktraceProcessor implements ProcessorInterface {

	/**
	 * Minimum logging level.
	 *
	 * @since  1.0.0
	 * @var    integer    $level    Minimum logging level.
	 */
	private $level;

	/**
	 * Initializes the class and set its properties.
	 *
	 * @param string|int $level The minimum logging level at which this Processor will be triggered
	 * @since   1.0.0
	 */
	public function __construct( $level = Logger::DEBUG ) {
		$this->level = Logger::toMonologLevel( $level );
	}

	/**
	 * Invocation of the processor.
	 *
	 * @param   array $trace  A "cleaned" value returned by debug_backtrace().
	 * @@return array   The pretty traces.
	 * @since   1.0.0
	 */
	private function pretty_backtrace( $trace ) {
		$result = [];
		foreach ( $trace as $index => $call ) {
			$file = ( array_key_exists( 'file', $call ) ? $call['file'] : '' );
			if ( '' === $file ) {
				$file = '[PHP Kernel]';
			} else {
				$file = './' . str_replace( wp_normalize_path( ABSPATH ), '', wp_normalize_path( $file ) );
			}
			$line     = ( array_key_exists( 'line', $call ) ? ':' . $call['line'] : '' );
			$class    = ( array_key_exists( 'class', $call ) ? $call['class'] : '' );
			$type     = ( array_key_exists( 'type', $call ) ? $call['type'] : '' );
			$function = ( array_key_exists( 'function', $call ) ? $call['function'] : '' );
			$args     = [];
			foreach ( array_key_exists( 'args', $call ) ? $call['args'] : [] as $arg ) {
				if ( is_object( $arg ) ) {
					$str = get_class( $arg );
				} elseif ( is_array( $arg ) ) {
					$str = 'Array';
				} elseif ( is_numeric( $arg ) ) {
					$str = $arg;
				} else {
					$str = "'$arg'";
				}
				$args[] = $str;
			}

			$result[ $index ]['file'] = $file . $line;
			$result[ $index ]['call'] = $class . $type . $function . '(' . implode( ', ', $args ) . ')';
		}
		return $result;
	}

	private function customErrorHandler( $code, $msg ) {
		return true;
	}

	/**
	 * Invocation of the processor.
	 *
	 * @param   array $record  Array or added records.
	 * @@return array   The modified records.
	 * @since   1.0.0
	 */
	public function __invoke( array $record ): array {
		// phpcs:ignore
		set_error_handler( null );
		if ( $record['level'] < $this->level ) {
			return $record;
		}
		try {
			$trace = [];
			$cpt   = 0;
			// phpcs:ignore
			foreach ( array_reverse( debug_backtrace( 0, 40 ) ) as $t ) {
				if ( array_key_exists( 'class', $t ) && ( 0 === strpos( $t['class'], 'Decalog\\' ) || false !== strpos( $t['class'], '\\System\\Logger' ) ) ) {
					break;
				}
				if ( 40 < $cpt ++ ) {
					break;
				}
				$trace[] = $t;
			}
		} catch ( \Throwable $t ) {
			//
		} finally {
			$record['extra']['trace']['callstack'] = $this->pretty_backtrace( array_reverse( $trace ) );
		}
		try {
			$wptrace = [];
			$cpt     = 0;
			// phpcs:ignore
			foreach ( array_reverse( wp_debug_backtrace_summary( null, 0, false ) ) as $t ) {
				if ( 0 === strpos( $t, 'Decalog\\' ) || false !== strpos( $t, '\\System\\Logger' ) ) {
					break;
				}
				if ( 40 < $cpt++ ) {
					break;
				}
				$wptrace[] = $t;
			}
		} catch ( \Throwable $t ) {
			//
		} finally {
			$record['extra']['trace']['wordpress'] = array_reverse( $wptrace );
		}
		restore_error_handler();
		return $record;
	}
}
