<?php
namespace Bolt\Tests;
use Bolt\Application;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Storage\MockFileSessionStorage;
use Symfony\Component\HttpFoundation\File\UploadedFile;
    
use Bolt\Configuration as Config;


/**
 * Class to test correct operation of Upload Controller.
 *
 * @author Ross Riley <riley.ross@gmail.com>
 **/


class UploadControllerTest extends \PHPUnit_Framework_TestCase
{
    
    
    public function setup()
    {
        @mkdir(__DIR__."/files", 0777, true);
    }
    
    public function tearDown()
    {
        $this->rmdir(__DIR__."/files");
        @rmdir(__DIR__.'/files');
    }
    
    
    /**
    * @runInSeparateProcess
    */
    public function testResponses()
    {
        global $app;
        $app = $this->getApp();
        
        $request = Request::create(
            "/upload/files",
            "POST",
            array(),
            array(),
            array(),
            array()  
        );

        $response = $app->handle($request);
        $this->assertEquals(200, $response->getStatusCode());

        // We haven't posted a file so an empty resultset should be returned
        $content = json_decode($response->getContent());
        $this->assertEquals("ERROR", $content->status);
        $this->assertEquals(0, count($content->files));
    }
    
    public function testUpload()
    {
        global $app;
        $app = $this->getApp();
        $request = Request::create(
            "/upload/files",
            "POST",
            array(),
            array(),
            array(
                "files"=>array(
                    'tmp_name'  => __DIR__."/resources/generic-logo.png",
                    'name'      => 'logo.png'
                )
            ),
            array()  
        );
        $response = $app->handle($request);
        $this->assertEquals(200, $response->getStatusCode());
        
        $content = json_decode($response->getContent());
        $this->assertEquals("OK", $content->status);
        $this->assertEquals(1, count($content->files));


    }
    
    public function testInvalidFiletype()
    {
        global $app;
        $app = $this->getApp();
        $request = Request::create(
            "/upload/files",
            "POST",
            array(),
            array(),
            array(
                "files"=>array(
                    'tmp_name'  => __DIR__."/resources/generic-logo-evil.exe",
                    'name'      => 'logo.exe'
                )
            ),
            array()  
        );
        
        $response = $app->handle($request);
        $this->assertEquals(200, $response->getStatusCode());
        
        $content = json_decode($response->getContent());
        $this->assertEquals("ERROR", $content->status);
        $this->assertEquals(1, count($content->messages));

    }
    
    
    protected function getApp()
    {
        $sessionMock = $this->getMockBuilder('Symfony\Component\HttpFoundation\Session\Session')
        ->setMethods(array('clear'))
        ->setConstructorArgs(array(new MockFileSessionStorage()))
        ->getMock();
                        
        $config = new Config\ResourceManager(__DIR__);
        $app = new Application(array('resources'=>$config));
        $app['config']->set('general/database', array('databasename'=>'test','username'=>'test'));
        $app['debug'] = false;
        $app['session'] = $sessionMock;
        $app->initialize();
        $app['resources']->setPath('files', __DIR__."/files");
        return $app;
    }
    
    protected function rmdir($dir) {  
        $iterator = new \RecursiveIteratorIterator( 
                            new \RecursiveDirectoryIterator($dir , \FilesystemIterator::SKIP_DOTS), 
                            \RecursiveIteratorIterator::CHILD_FIRST
                        );
        foreach ($iterator as $file) {  
            if ($file->isDir()) {  
                rmdir($file->getPathname());  
            } else {  
                unlink($file->getPathname());  
            }  
        }  
    } 


}

