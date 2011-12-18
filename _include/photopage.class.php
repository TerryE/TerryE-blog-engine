<?php
/** 
 *  Process photo page. This has three sub-modes 
 *												Admin Options
 *  *	N 		display an individual photo     Delete Edit
 *  *	album	display a list of all albums	Create(album) 
 *  *	album-N	display a specific album		Delete Edit Create(photo)
 *
 * If the user is also a logged on and an admin then the above options are also offered
 */
class PhotoPage extends Page {

	function __construct() {

		parent::__construct();
		$subPage = $this->cxt->subPage;
		$subOpt  = $this->cxt->subOpt;

		// Define AppDB access functions used in PhotoPage

		$this->db->declareFunction( array(
'getAlbums'			=> "Set=SELECT * FROM :photo_albums WHERE flag >= #1", 
'updateAlbums'		=> "UPDATE :photo_albums SET flag=#1, title='#2', description= '#3' WHERE id=#4",
'deleteAlbum'		=> "DELETE FROM :photo_albums USING FROM :photo_albums pa " . 
						"LEFT JOIN :photos p ON pa.id=p.album_id " . 
						"WHERE p.id=#1 AND p.album_id IS NULL",
'insertAlbum'		=> "INSERT INTO :photo_albums (flag, title, description) VALUES ( #1, '#2', '#3' )",
'getPhotos'			=> "Set=SELECT * FROM :photos WHERE album_id=#1 AND flag >= #2",
'getPhotoCount'		=> "Val=SELECT COUNT(*) AS count FROM :photo_albums WHERE title='#1'",
'getPhoto'			=> "Row=SELECT * FROM :photos WHERE title='#1' LIMIT 1",
'getPhotoDetails'	=> "Row=SELECT p.id AS id, p.title AS title, p.description AS description, 
								   p.filename AS filename, p.flag AS flag, a.title AS album_name, a.id AS album_id 
							FROM  :photos p INNER JOIN :photo_albums a ON p.album_id = a.id
							WHERE  p.id=#1 AND p.flag>=#2 AND a.flag>=#2",
'insertPhoto'		=> "INSERT INTO :photos (title, description, album_id) VALUES ( #1, '#2', '#3' )",
'deletePhoto'		=> "DELETE FROM :photos WHERE title='#1'",
'updatePhotoCnt'	=> "UPDATE :photo_albums SET photo_count = (SELECT COUNT(*) FROM :photos WHERE album_id=#1) WHERE id=#1",
'updatePhotoDesc'	=> "UPDATE :photos SET description='#2' WHERE id=#1",
'updatePhoto'		=> "UPDATE :photos SET title='#2', description='#3', flag=#4 WHERE id=#1",
		) );

		// Despatch to correct sub-page processing routine

		if( is_numeric( $subPage ) ) {
			$this->photo( $subPage );
		} elseif( $subPage == 'album' ) {
			$id = is_numeric( $subOpt ) ? $subOpt : NULL;
			$this->album( $id );
		} else {
			$this->invalidPage();
		} 
	}
	/**
	 * Process an album or all albums if the id is NULL
	 * @param $id int  Id of album
	 */
	private function album( $id ) {

		$cxt   = $this->cxt;
		$admin = $cxt->isAdmin;
		$db    = $this->db;
		$cxt->allow( ':request_album:request_edit:request_photo:create:delete:update:add_photo' . 
									':album:album_id:description:flag:title' );
		$id    = $cxt->album_id ? $cxt->album_id : $id; 

		if( $admin ) {
			/**
			 * Process the post form if an admin (ignore otherwise) with the following actions:
			 *
			 *	request_edit	button reqesting display/edit of selected album details
			 *	request_photo	button reqesting the post of a new photo to the selected album
			 *	request_album	button reqesting display of the "create new album form"
			 *	update			update selected album details
			 *	delete			delete selected album (if the photo count is zero)
			 *	create			create a new album
			 *  add_photo		add a new photo to the specified album
			 *
			 * Note that only existance is used as the actual value of the button is language-specific.
			 */
			if( $cxt->request_edit ) {
				$showEdit = true;

			} elseif( $cxt->request_photo ) {
				$showCreatePhoto = true;

			} elseif( $cxt->request_album ) {
				$showNew = true;

			} elseif( $cxt->update ) {
				$flag = $cxt->flag ? 1 : 0;

				if( $db->getPhotoCount( $cxt->album ) == 0 ) {
					$db->updatePhotoAlbums( $flag, $cxt->album, $cxt->description, $id );
				} else {
					$error = "An album with that name already exists.";
				}

			} elseif( $cxt->delete ) {
				$db->deleteAlbum( $id );

			} elseif( $cxt->create ) {
				$rec  = $db->getPhotoCount( $cxt->album );
				if( $cxt->album != '' && $rec['count'] == 0 ) {
					$db->insertAlbum( 0, $cxt->album, $cxt->description );
				}

			} elseif( $cxt->add_photo ) {
				$cxt->allow( '!newimage' );
				$title    = $cxt->title;
				$newImage = $cxt->newimage;

				if( count( $db->getPhoto( $title ) ) == 0 && $newImage !== FALSE ) {
					$db->insertPhoto( $title, $cxt->description, $id );

					$photo=$db->getPhoto( $title );

					if( !$this->processImage( $newimage, $photo['album_id'], $photo['id'] ) ) {
						$db->deletePhoto( $title );
						$error = "Invalid image format";
					}

					$db->updatePhotoCnt( $id );
				} else {
					$error = "You must specify a valid upload image";
				}
			} 
	 	}

		if( isset( $id ) ) {
			$photos = $db->getPhotos( $id, $admin ? 0 : 1 );
		} else {
			$photos = NULL;
		}

		$this->assign( array (
			'albums' => $db->getAlbums( $admin ? 0 : 1 ), 
			'photos' => isset( $photos ) ? $photos : NULL,
			'showid' => isset( $id) ? $id : 0,
			'showedit' => isset( $showEdit ),
			'shownew' => isset( $showNew ),
			'showcreatephoto' => isset( $showCreatePhoto ),
			'error'  => isset( $error ) ? $error : NULL, 
			) );

		echo $this->output( 'album' );
	}

	/**
	 * Process a single image
	 * @param $id int  Id of the photo
	 */
	private function photo( $id ) {
		
		$cxt     = $this->cxt;
		$admin   = $cxt->isAdmin;
		$rootDir = $cxt->rootDir;
		$db      = $this->db;
		$r       = $this->cxt->allow( $admin ? ':delete:update' : '' );

		# get the image and raise an error if it doesn't exist
		
		$photo = $db->getPhotoDetails ( $id, $admin ? 0 : 1 );
		$error = '';

		if( !isset( $photo['id'] ) ) {
			$this->invalidPage();
		}

		# Process the post form if an admin, and ignore otherwise

		if( $admin && $cxt->delete ) {

			if( $cxt->title == $photo['title'] ) {
				$album = $photo['album_id'];
				$db->query( "DELETE FROM :photos WHERE id=$id" );

				@unlink( "$rootDir/images/photos/thumbnail/$id.jpg" );
				@unlink( "$rootDir/images/photos/full/$id.jpg" );

				$db->updatePhotoCnt( $album );

				# After a sucessful delete, switch to album view
				header( "Location: photo-album-$album" );
				return; # bypass this output because photoAlbum has done this!

			} else {
				$error = "The title entered does not match the photo title.";
			}

		} elseif( $admin && $cxt->update ) {

			$cxt->allow( ':title:description:flag!replacement' );
			$title       = $cxt->title;
			$description = $cxt->description;
			$flag        = $cxt->flag ? 1 : 0;
			$file		 = $cxt->replacement;

			if( is_uploaded_file( $file['tmp_name'] ) &&  $cxt->title == $photo['title'] ) {
				if( $this->photoProcessImage( $file, $photo['album'], $photo['id'] ) ) {
					if( $description != $photo['description'] ) {
						$db->queryUpdatePhotoDesc( $id, $description );
					}
				} else {
					$error = "The title must match the recorded title if you want to update the image.";
				} 
			} else {
				$db->updatePhoto( $id, $title, $description, $flag );
			}
			$photo['title'] = $title;
			$photo['description'] = $description;
			$photo['flag'] = $flag;
		}

		$this->assign( array (
			'photo' => $photo,
			'error' => $error,
			'admin' => $this->cxt->user,
			) );

		echo $this->output( 'photo' );
	}

	const MAX_THUMB_PIXELS = 170;
	const JPEG_QUALITY = 90;

	/**
	 * Move the image to the correct folder and produce a thumbnail. This method makes use
	 * of the GD library functions.
	 * @param $fileInfo  File context array for the uploaded file
	 * @param $album int Id of the album which will contain the photo 
	 * @param $id int    Id of the photo itself
	 */
	function processImage( $fileInfo, $album, $id ) {

		$filetype = $fileInfo['type'];
		$imagePath = $this->cxt->rootDir . "/images/photos";

		if( in_array( $filetype, array( 'image/png', 'image/jpeg' ) ) ) {
			$imageType = $filetype == 'image/jpeg' ? 'jpeg' : 'png' ;

			$uriThumb = "$imagePath/thumbnail/$id.jpg";
			$uriFull = "$imagePath/full/$id.$imageType";

			if( !move_uploaded_file( $fileInfo['tmp_name'], $uriFull ) ) return;
			$image = $filetype == 'image/jpeg' ? imagecreatefromjpeg( $uriFull ) : imagecreatefrompng( $uriFull ) ;
			$w = imagesx($image); $h = imagesy($image);
			$wScale = this::MAX_THUMB_PIXELS/$w; $hScale = this::MAX_THUMB_PIXELS/$h;
			$scale = ( $wScale < $hScale ) ? $wScale : $hScale; 

			if( $scale < 1 ) {
				# create a new thumbnail  
				$wThumb = round( $scale*$w ); $hThumb = round( $scale*$h );
				$thumb = imagecreatetruecolor($wThumb, $hThumb);
				imagecopyresampled( $thumb, $image, 0,0, 0,0, $wThumb,$hThumb, $w,$h );
				imagejpeg( $thumb, $uriThumb, this::JPEG_QUALITY );
				imagedestroy( $thumb );
			} else {
				# the image is small enough to use as a thumbnail itself;
				copy( $uriFull, $uriThumb );
			}
			imagedestroy( $image );
		}
		return true;
	}
}
