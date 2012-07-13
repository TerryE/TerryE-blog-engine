<?php
/**
 * App builder for autoload classes. See AbstractBuilder documentation for a detailed discussion
 * of the builder strategy.
 */
class AppBuilder extends AbstractBuilder {
	/**
	 * There is a standard DefaultBuilder which able to gather source classes and compact them to 
	 * a new version in the _cache directory.  However, some core application classes are themselves 
	 * needed in this default builder process and therefore can't be build by it.  They way I avoid
	 * this catch-22 is by creating an App class group which is built by this builder, and which
	 * has no dependencies on any of the App class objects
	 *
	 * For these App classes, the build process is essential a no-operation. It simply returns the 
	 * filename of the include copy.
	 *
	 * Note that this does not preclude their inclusion in a "##require" by the DefaultBuilder so
	 * there is not material runtime cost of doing this simplification.
	 *
	 * @param $className  Name of class to be build
	 * @param $includeDir Bootstrap context include directory
	 * @param $dummy      Dummied AppContext object
	 */
	public  function __construct( $className, $includeDir, $dummy ) {

		// Note that AppContext cannot be assumed to have completed, so no object will be avaiable

		$fileName = preg_replace( '/[^a-z]/', '.', strtolower( $className ) ) . '.class.php';
		$this->loadFile = $includeDir . $fileName;
	}
}

