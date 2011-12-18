<?php
/**
 * Builder for TinyMCE_Compressor class.  The only reason for having this builder is 
 * that the standard tiny_mce_gzip.php file includes callback URI request handling
 * and is located in a \b tinymce directory.
 * 
 * See AbstractBuilder documentation for a detailed discussion of the builder strategy.
 */
class TinyBuilder extends AbstractBuilder {
	/**
	 * Standard method for autoloaded builder classes.
	 */
	public static function build ( $className ) {

		if(  $className != 'TinyMCE_Compressor' ) {
			throw new Exception( "APP: Unknown class $className" );
		}
		$cxt		= AppContext::get();
		$inFile		= $cxt->rootDir . '/includes/tinymce/tiny_mce_gzip.php';
		$outFile	= $cxt->cacheDir . 'tinymce.compressor.class.php';

		if( $cxt->debugLevel == 2 ) {
			return $inFile;
		} else {
			$compSrc 	= file_get_contents( $inFile );
			$incSrc		= substr( $compSrc, strpos( $compSrc, "\nclass TinyMCE_Compressor" ) );
			file_put_contents( $outFile, "<?php\n" . $incSrc, LOCK_EX );
			return $outFile;
		}
	}
}
