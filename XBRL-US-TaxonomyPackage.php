<?php

/**
 * XBRL US Taxonomy Package handler
 *
 * @author Bill Seddon
 * @version 0.9
 * @Copyright (C) 2018 Lyquidity Solutions Limited
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */

/**
 * Implements functions to read a US taxonomy package.  The US SEC taxonomy zip files
 * have a distinctive structure.  The root folder is of the form 'us-gaap-yyyy-mm-dd'
 * Beneath this folder are four folders: 'dis', 'elts', 'entire' and 'stm'.
 *
 */
class XBRL_US_TaxonomyPackage extends XBRL_SimplePackage
{
	/**
	 * Notes about using this package instance
	 * @var string
	 */
	const notes = <<<EOT
A US taxonomy package implementation will determine then namespace and schema file to use based on the name of the
root folder which includes the date.  These will be used to create a path to use in the cache.  The packages will
not include an instance document.\n\n
EOT;

	/**
	 * The actual path to the schema file
	 * @var string
	 */
	private $actualSchemaFilename;

	/**
	 * The year of the taxonomy
	 * @var string
	 */
	private $taxonomyYear;

	/**
	 * The prefix used for filenames
	 * @var string
	 */
	const filePrefix = "http://xbrl.fasb.org/us-gaap/";

	/**
	 * The prefix used for namespaces
	 * @var string
	 */
	const namespacePrefix = "http://fasb.org/us-gaap/";

	/**
	 * Provides a URL for the entity publishing the taxonomy. This element SHOULD be used to provide
	 * the primary website of the publishing entity. The URL used SHOULD be the same as that used in
	 * other Taxonomy Packages published by the same entity.
	 * @var string
	 */
	public $publisherURL;

	/**
	 * Provides a date on which the taxonomy was published.
	 * @var string
	 */
	public $publicationDate = "";

	/**
	 * Default constructor
	 * @param ZipArchive $zipArchive
	 */
	public function __construct( ZipArchive $zipArchive  )
	{
		parent::__construct( $zipArchive );

		$this->publisherURL = XBRL_US_TaxonomyPackage::filePrefix;
	}

	/**
	 * Returns true if the zip file represents an SEC package
	 * {@inheritDoc}
	 * @see XBRL_IPackage::isPackage()
	 */
	public function isPackage()
	{
		// A simple package does not have a manifest file
		try
		{
			// There must be just one root folder with the format 'us-gaap-yyyy-mm-dd'
			if ( count( $this->contents ) != 1 ) return false;

			$root = $this->getFirstFolderName();
			$matches = false;
			if ( ! preg_match( "/^us-gaap-(?<date>(?<year>\d{4,4})-\d{2,2}-\d{2,2})$/", $root, $matches ) )
			{
				return false;
			}

			$this->publicationDate = $matches['date'];

			$found = false;
			$this->traverseContents( function( $path, $name, $type ) use( &$found )
			{
				if ( $type == PATHINFO_DIRNAME ) return true;
				$found = $name == XBRL_SEC_XML_Package::manifestFilename || XBRL::endsWith( $name, '.json' );
				return ! $found;
			} );

			if ( $found )
			{
				throw XBRL_TaxonomyPackageException::withError( "tpe:metadataFileFound", "The package contains a manifest.xml file or JSON manifest file." );
			}

			$this->taxonomyYear = $matches['year'];
			$this->actualSchemaFilename = "{$root}/elts/us-gaap-{$matches['date']}.xsd";
			$contents = $this->getFile( $this->actualSchemaFilename );
			if ( ! $contents )
			{
				throw XBRL_TaxonomyPackageException::withError( "tpe:noSchemaFileFound", "The package must contain a schema file (.xsd)." );
			}

			$this->schemaNamespace = XBRL_US_TaxonomyPackage::namespacePrefix . "{$matches['date']}/";
			$this->schemaFile = XBRL_US_TaxonomyPackage::filePrefix . "{$this->taxonomyYear}/elts/us-gaap-{$matches['date']}.xsd";

			return true;
		}
		catch ( Exception $ex )
		{
			$code = $ex instanceof XBRL_TaxonomyPackageException ? $ex->error : $ex->getCode();
			$this->errors[ $code ] = $ex->getMessage();
		}

		return false;
	}

	/**
	 * Workout which file is the schema file
	 * @return void
	 * @throws "tpe:schemaFileNotFound"
	 */
	protected function determineSchemaFile()
	{
		// This must do nothing because the schema file and namespace are set by the isPackage function
	}

	/**
	 * Returns a localized version of the schema file path
	 * @param string $uri
	 * @return string
	 */
	protected function getActualUri( $uri )
	{
		return $this->actualSchemaFilename;
	}

	/**
	 * Returns XBRL_US_GAAP_2015
	 */
	public function getXBRLClassname()
	{
		return "XBRL_US_GAAP_2015";
	}

	/**
	 * Returns an array containing the root folder for the actual uri and the url
	 * @param string $actualUri
	 * @param string $uri
	 * @return string[]
	 */
	public function getCommonRootFolder( $actualUri, $uri )
	{
		// The uri will be like: http://xbrl.fasb.org/us-gaap/2016/elts/my.xsd
		// The actualUri will be like: us-gaap-2016-01-31/dis/us-gaap-dis-acec-def-2016-01-31.xml
		// The equivalents are http://xbrl.fasb.org/us-gaap/2016/ and us-gaap-2016-01-31

		// $actualSuffix = str_replace( $this->getFirstFolderName(), "", $actualUri );
		$pos = strpos( $actualUri, $this->getFirstFolderName() );
		$actualSuffix = $pos !== false && $pos == 0
			? substr_replace( $actualUri, '', $pos, strlen( $this->getFirstFolderName() ) )
			: $actualUri;
		$uri = XBRL_US_TaxonomyPackage::filePrefix . "{$this->taxonomyYear}$actualSuffix";

		return array(
			'actual' => $actualUri,
			'uri' => $uri
		);
	}

	/**
	 * Returns an array of schema file names defined as entry points
	 */
	public function getSchemaEntryPoints()
	{
		$entryPoints = array();

		$this->traverseContents( function( $path, $name, $type ) use( &$entryPoints )
		{
			if ( $type == PATHINFO_DIRNAME ) return true;
			if ( ! XBRL::compiled_taxonomy_for_xsd( $name ) ) return true;
			$common = $this->getCommonRootFolder( "$path$name", $this->schemaFile );
			$entryPoints[] = $common['uri'];
			return true;
		} );

		return $entryPoints;
	}
}