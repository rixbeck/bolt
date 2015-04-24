<?php

namespace Bolt\Controllers;

use Bolt\Helpers\Input;
use Bolt\Library as Lib;
use Bolt\Permissions;
use Bolt\Translation\TranslationFile;
use Bolt\Translation\Translator as Trans;
use Cocur\Slugify\Slugify;
use Guzzle\Http\Exception\RequestException as V3RequestException;
use GuzzleHttp\Exception\RequestException;
use Silex;
use Silex\Application;
use Silex\ControllerProviderInterface;
use Symfony;
use Symfony\Component\Form\Form;
use Symfony\Component\Form\FormBuilder;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Yaml;
use Symfony\Component\Yaml\Exception\ParseException;
use League\Flysystem\FileNotFoundException;

/**
 * Backend controller grouping.
 *
 * This implements the Silex\ControllerProviderInterface to connect the controller
 * methods here to whatever back-end route prefix was chosen in your config. This
 * will usually be "/bolt".
 */
class Backend implements ControllerProviderInterface
{
    /**
     * {@inheritdoc}
     */
    public function connect(Application $app)
    {
        /** @var $ctl \Silex\ControllerCollection */
        $ctl = $app['controllers_factory'];

        $ctl->before(array($this, 'before'));
        $ctl->method('GET|POST');

        $ctl->get('/overview/{contenttypeslug}', array($this, 'overview'))
            ->bind('overview');

        $ctl->get('/relatedto/{contenttypeslug}/{id}', array($this, 'relatedTo'))
            ->assert('id', '\d*')
            ->bind('relatedto');

        $ctl->match('/editcontent/{contenttypeslug}/{id}', array($this, 'editContent'))
            ->assert('id', '\d*')
            ->value('id', '')
            ->bind('editcontent');

        $ctl->get('/content/deletecontent/{contenttypeslug}/{id}', array($this, 'deleteContent'))
            ->bind('deletecontent');

        $ctl->post('/content/{action}/{contenttypeslug}/{id}', array($this, 'contentAction'))
            ->bind('contentaction');

        $ctl->get('/systemlog', array($this, 'systemLog'))
            ->bind('systemlog');

        $ctl->get('/changelog', array($this, 'changeLog'))
            ->bind('changelog');

        $ctl->get('/changelog/{contenttype}/{contentid}', array($this, 'changelogRecordAll'))
            ->value('contentid', '0')
            ->value('contenttype', '')
            ->bind('changelogrecordall');

        $ctl->get('/changelog/{contenttype}/{contentid}/{id}', array($this, 'changelogRecordSingle'))
            ->assert('id', '\d*')
            ->bind('changelogrecordsingle');

        $ctl->get('/users', array($this, 'users'))
            ->bind('users');

        $ctl->match('/users/edit/{id}', array($this, 'userEdit'))
            ->assert('id', '\d*')
            ->bind('useredit');

        $ctl->match('/userfirst', array($this, 'userFirst'))
            ->bind('userfirst');

        $ctl->match('/profile', array($this, 'profile'))
            ->bind('profile');

        $ctl->get('/roles', array($this, 'roles'))
            ->bind('roles');

        $ctl->post('/user/{action}/{id}', array($this, 'userAction'))
            ->bind('useraction');

        $ctl->match('/files/{namespace}/{path}', array($this, 'files'))
            ->assert('namespace', '[^/]+')
            ->assert('path', '.*')
            ->value('namespace', 'files')
            ->value('path', '')
            ->bind('files');

        $ctl->match('/file/edit/{namespace}/{file}', array($this, 'fileEdit'))
            ->assert('file', '.+')
            ->assert('namespace', '[^/]+')
            ->value('namespace', 'files')
            ->bind('fileedit')
            // Middleware to disable browser XSS protection whilst we throw code around
            ->after(function(Request $request, Response $response) {
                if ($request->getMethod() == "POST") {
                    $response->headers->set('X-XSS-Protection', '0');
                }
            });

        return $ctl;
    }

    /**
     * Show a list of all available users.
     *
     * @param Application $app The application/container
     *
     * @return \Twig_Markup
     */
    public function users(Application $app)
    {
        $currentuser = $app['users']->getCurrentUser();
        $users = $app['users']->getUsers();
        $sessions = $app['users']->getActiveSessions();

        foreach ($users as $name => $user) {
            if (($key = array_search(Permissions::ROLE_EVERYONE, $user['roles'], true)) !== false) {
                unset($users[$name]['roles'][$key]);
            }
        }

        $context = array(
            'currentuser' => $currentuser,
            'users'       => $users,
            'sessions'    => $sessions
        );

        return $app['render']->render('users/users.twig', array('context' => $context));
    }

    /**
     * Show the roles page.
     *
     * @param Application $app The application/container
     *
     * @return \Twig_Markup
     */
    public function roles(Application $app)
    {
        $contenttypes = $app['config']->get('contenttypes');
        $permissions = array('view', 'edit', 'create', 'publish', 'depublish', 'change-ownership');
        $effectivePermissions = array();
        foreach ($contenttypes as $contenttype) {
            foreach ($permissions as $permission) {
                $effectivePermissions[$contenttype['slug']][$permission] =
                    $app['permissions']->getRolesByContentTypePermission($permission, $contenttype['slug']);
            }
        }
        $globalPermissions = $app['permissions']->getGlobalRoles();

        $context = array(
            'effective_permissions' => $effectivePermissions,
            'global_permissions'    => $globalPermissions,
        );

        return $app['render']->render('roles/roles.twig', array('context' => $context));
    }

    /**
     * Edit a user.
     *
     * @param integer     $id      The user ID
     * @param Application $app     The application/container
     * @param Request     $request The Symfony Request
     *
     * @return \Twig_Markup|\Symfony\Component\HttpFoundation\RedirectResponse
     */
    public function userEdit($id, Application $app, Request $request)
    {
        $currentuser = $app['users']->getCurrentUser();

        // Get the user we want to edit (if any)
        if (!empty($id)) {
            $user = $app['users']->getUser($id);

            if (is_array($user)) {
                // Verify the current user has access to edit this user
                if (!$app['permissions']->isAllowedToManipulate($user, $currentuser)) {
                    $app['session']->getFlashBag()->add('error', Trans::__('You do not have the right privileges to edit that user.'));

                    return Lib::redirect('users');
                }
            } else {
                $app['session']->getFlashBag()->add('error', Trans::__('No such user.'));

                return Lib::redirect('users');
            }
        } else {
            $user = $app['users']->getEmptyUser();
        }

        $enabledoptions = array(
            1 => Trans::__('page.edit-users.activated.yes'),
            0 => Trans::__('page.edit-users.activated.no')
        );

        $roles = array_map(
            function ($role) {
                return $role['label'];
            },
            $app['permissions']->getDefinedRoles()
        );

        $form = $this->getUserForm($app, $user, true);

        // New users and the current users don't need to disable themselves
        if ($currentuser['id'] != $id) {
            $form->add(
                'enabled',
                'choice',
                array(
                    'choices'     => $enabledoptions,
                    'expanded'    => false,
                    'constraints' => new Assert\Choice(array_keys($enabledoptions)),
                    'label'       => Trans::__('page.edit-users.label.user-enabled'),
                )
            );
        }

        $form
            ->add(
                'roles',
                'choice',
                array(
                    'choices'  => $roles,
                    'expanded' => true,
                    'multiple' => true,
                    'label'    => Trans::__('page.edit-users.label.assigned-roles')
                )
            )
            ->add(
                'lastseen',
                'text',
                array(
                    'disabled' => true,
                    'label'    => Trans::__('page.edit-users.label.last-seen')
                )
            )
            ->add(
                'lastip',
                'text',
                array(
                    'disabled' => true,
                    'label'    => Trans::__('page.edit-users.label.last-ip')
                )
            );

        // Set the validation
        $form = $this->setUserFormValidation($app, $form, true);

        $form = $form->getForm();

        // Check if the form was POST-ed, and valid. If so, store the user.
        if ($request->isMethod('POST')) {
            $user = $this->validateUserForm($app, $form);

            $currentuser = $app['users']->getCurrentUser();

            if ($user !== false && $user['id'] === $currentuser['id'] && $user['username'] !== $currentuser['username']) {
                // If the current user changed their own login name, the session is effectively
                // invalidated. If so, we must redirect to the login page with a flash message.
                $app['session']->getFlashBag()->add('error', Trans::__('page.edit-users.message.change-self'));

                return Lib::redirect('login');
            } elseif ($user !== false) {
                // Return to the 'Edit users' screen.
                return Lib::redirect('users');
            }
        }

        /** @var \Symfony\Component\Form\FormView|\Symfony\Component\Form\FormView[] $formView */
        $formView = $form->createView();

        $manipulatableRoles = $app['permissions']->getManipulatableRoles($currentuser);
        foreach ($formView['roles'] as $role) {
            if (!in_array($role->vars['value'], $manipulatableRoles)) {
                $role->vars['attr']['disabled'] = 'disabled';
            }
        }

        $context = array(
            'kind'        => empty($id) ? 'create' : 'edit',
            'form'        => $formView,
            'note'        => '',
            'displayname' => $user['displayname'],
        );

        return $app['render']->render('edituser/edituser.twig', array('context' => $context));
    }

    /**
     * Create the first user.
     *
     * @param Application $app
     * @param Request     $request
     *
     * @return \Twig_Markup|\Symfony\Component\HttpFoundation\RedirectResponse
     */
    public function userFirst(Application $app, Request $request)
    {
        // We should only be here for creating the first user
        if ($app['integritychecker']->checkUserTableIntegrity() && $app['users']->hasUsers()) {
            return Lib::redirect('dashboard');
        }

        // Get and empty user array
        $user = $app['users']->getEmptyUser();

        // Add a note, if we're setting up the first user using SQLite.
        $dbdriver = $app['config']->get('general/database/driver');
        if ($dbdriver === 'sqlite' || $dbdriver === 'pdo_sqlite') {
            $note = Trans::__('page.edit-users.note-sqlite');
        } else {
            $note = '';
        }

        // If we get here, chances are we don't have the tables set up, yet.
        $app['integritychecker']->repairTables();

        // Grant 'root' to first user by default
        $user['roles'] = array(Permissions::ROLE_ROOT);

        // Get the form
        $form = $this->getUserForm($app, $user, true);

        // Set the validation
        $form = $this->setUserFormValidation($app, $form, true);

        /** @var \Symfony\Component\Form\Form */
        $form = $form->getForm();

        // Check if the form was POST-ed, and valid. If so, store the user.
        if ($request->isMethod('POST')) {
            if ($this->validateUserForm($app, $form, true)) {
                // To the dashboard, where 'login' will be triggered
                return $app->redirect(Lib::path('dashboard'));
            }
        }

        $context = array(
            'kind'        => 'create',
            'form'        => $form->createView(),
            'note'        => $note,
            'displayname' => $user['displayname'],
        );

        return $app['render']->render('firstuser/firstuser.twig', array('context' => $context));
    }

    /**
     * Handle a POST from user edit or first user creation.
     *
     * @param \Silex\Application          $app
     * @param Symfony\Component\Form\Form $form      A Symfony form
     * @param boolean                     $firstuser If this is a first user set up
     *
     * @return array|boolean An array of user elements, otherwise false
     */
    private function validateUserForm(Application $app, Form $form, $firstuser = false)
    {
        $form->submit($app['request']->get($form->getName()));

        if ($form->isValid()) {
            $user = $form->getData();

            if ($firstuser) {
                $user['roles'] = array(Permissions::ROLE_ROOT);
            } else {
                $id = isset($user['id']) ? $user['id'] : null;
                $user['roles'] = $app['users']->filterManipulatableRoles($id, $user['roles']);
            }

            $res = $app['users']->saveUser($user);

            if ($user['id']) {
                $app['logger.system']->info(Trans::__('page.edit-users.log.user-updated', array('%user%' => $user['displayname'])), array('event' => 'security'));
            } else {
                $app['logger.system']->info(Trans::__('page.edit-users.log.user-added', array('%user%' => $user['displayname'])), array('event' => 'security'));

                // Create a welcome email
                $mailhtml = $app['render']->render(
                    'email/firstuser.twig',
                    array(
                        'sitename' => $app['config']->get('general/sitename')
                    )
                )->getContent();

                try {
                    // Send a welcome email
                    $message = $app['mailer']
                        ->createMessage('message')
                        ->setSubject(Trans::__('New Bolt site has been set up'))
                        ->setFrom(array($app['config']->get('general/mailoptions/senderMail', $user['email']) => $app['config']->get('general/mailoptions/senderName', $app['config']->get('general/sitename'))))
                        ->setTo(array($user['email']   => $user['displayname']))
                        ->setBody(strip_tags($mailhtml))
                        ->addPart($mailhtml, 'text/html');

                    $app['mailer']->send($message);
                } catch (\Exception $e) {
                    // Sending message failed. What else can we do, sending with snailmail?
                    $app['logger.system']->error("The 'mailoptions' need to be set in app/config/config.yml", array('event' => 'config'));
                }
            }

            if ($res) {
                $app['session']->getFlashBag()->add('success', Trans::__('page.edit-users.message.user-saved', array('%user%' => $user['displayname'])));
            } else {
                $app['session']->getFlashBag()->add('error', Trans::__('page.edit-users.message.saving-user', array('%user%' => $user['displayname'])));
            }

            return $user;
        }

        return false;
    }

    /**
     * User profile page.
     *
     * @param Application $app     The application/container
     * @param Request     $request The Symfony Request
     *
     * @return \Twig_Markup|\Symfony\Component\HttpFoundation\RedirectResponse
     */
    public function profile(Application $app, Request $request)
    {
        $user = $app['users']->getCurrentUser();

        // Get the form
        $form = $this->getUserForm($app, $user);

        // Set the validation
        $form = $this->setUserFormValidation($app, $form);

        /** @var \Symfony\Component\Form\Form */
        $form = $form->getForm();

        // Check if the form was POST-ed, and valid. If so, store the user.
        if ($request->isMethod('POST')) {
            $form->submit($app['request']->get($form->getName()));

            if ($form->isValid()) {
                $user = $form->getData();

                $res = $app['users']->saveUser($user);
                $app['logger.system']->info(Trans::__('page.edit-users.log.user-updated', array('%user%' => $user['displayname'])), array('event' => 'security'));
                if ($res) {
                    $app['session']->getFlashBag()->add('success', Trans::__('page.edit-users.message.user-saved', array('%user%' => $user['displayname'])));
                } else {
                    $app['session']->getFlashBag()->add('error', Trans::__('page.edit-users.message.saving-user', array('%user%' => $user['displayname'])));
                }

                return Lib::redirect('profile');
            }
        }

        $context = array(
            'kind'        => 'profile',
            'form'        => $form->createView(),
            'note'        => '',
            'displayname' => $user['displayname'],
        );

        return $app['render']->render('edituser/edituser.twig', array('context' => $context));
    }

    /**
     * Perform actions on users.
     *
     * @param Application $app    The application/container
     * @param string      $action The action
     * @param integer     $id     The user ID
     *
     * @return \Symfony\Component\HttpFoundation\RedirectResponse
     */
    public function userAction(Application $app, $action, $id)
    {
        if (!$app['users']->checkAntiCSRFToken()) {
            $app['session']->getFlashBag()->add('info', Trans::__('An error occurred.'));

            return Lib::redirect('users');
        }
        $user = $app['users']->getUser($id);

        if (!$user) {
            $app['session']->getFlashBag()->add('error', Trans::__('No such user.'));

            return Lib::redirect('users');
        }

        // Prevent the current user from enabling, disabling or deleting themselves
        $currentuser = $app['users']->getCurrentUser();
        if ($currentuser['id'] == $user['id']) {
            $app['session']->getFlashBag()->add('error', Trans::__("You cannot '%s' yourself.", array('%s', $action)));

            return Lib::redirect('users');
        }

        // Verify the current user has access to edit this user
        if (!$app['permissions']->isAllowedToManipulate($user, $currentuser)) {
            $app['session']->getFlashBag()->add('error', Trans::__('You do not have the right privileges to edit that user.'));

            return Lib::redirect('users');
        }

        switch ($action) {

            case 'disable':
                if ($app['users']->setEnabled($id, 0)) {
                    $app['logger.system']->info("Disabled user '{$user['displayname']}'.", array('event' => 'security'));

                    $app['session']->getFlashBag()->add('info', Trans::__("User '%s' is disabled.", array('%s' => $user['displayname'])));
                } else {
                    $app['session']->getFlashBag()->add('info', Trans::__("User '%s' could not be disabled.", array('%s' => $user['displayname'])));
                }
                break;

            case 'enable':
                if ($app['users']->setEnabled($id, 1)) {
                    $app['logger.system']->info("Enabled user '{$user['displayname']}'.", array('event' => 'security'));
                    $app['session']->getFlashBag()->add('info', Trans::__("User '%s' is enabled.", array('%s' => $user['displayname'])));
                } else {
                    $app['session']->getFlashBag()->add('info', Trans::__("User '%s' could not be enabled.", array('%s' => $user['displayname'])));
                }
                break;

            case 'delete':

                if ($app['users']->checkAntiCSRFToken() && $app['users']->deleteUser($id)) {
                    $app['logger.system']->info("Deleted user '{$user['displayname']}'.", array('event' => 'security'));
                    $app['session']->getFlashBag()->add('info', Trans::__("User '%s' is deleted.", array('%s' => $user['displayname'])));
                } else {
                    $app['session']->getFlashBag()->add('info', Trans::__("User '%s' could not be deleted.", array('%s' => $user['displayname'])));
                }
                break;

            default:
                $app['session']->getFlashBag()->add('error', Trans::__("No such action for user '%s'.", array('%s' => $user['displayname'])));

        }

        return Lib::redirect('users');
    }

    /**
     * The file browser.
     *
     * @param string      $namespace The filesystem namespace
     * @param string      $path      The path prefix
     * @param Application $app       The application/container
     * @param Request     $request   The Symfony Request
     *
     * @return \Twig_Markup
     */
    public function files($namespace, $path, Application $app, Request $request)
    {
        // No trailing slashes in the path.
        $path = rtrim($path, '/');

        // Defaults
        $files      = array();
        $folders    = array();
        $formview   = false;
        $uploadview = true;

        $filesystem = $app['filesystem']->getFilesystem($namespace);

        if (!$filesystem->authorized($path)) {
            $error = Trans::__("You don't have the correct permissions to display the file or directory '%s'.", array('%s' => $path));
            $app->abort(Response::HTTP_FORBIDDEN, $error);
        }

        if (!$app['users']->isAllowed('files:uploads')) {
            $uploadview = false;
        }

        try {
            $visibility = $filesystem->getVisibility($path);
        } catch (FileNotFoundException $fnfe) {
            $visibility = false;
        }

        if ($visibility === 'public') {
            $validFolder = true;
        } elseif ($visibility === 'readonly') {
            $validFolder = true;
            $uploadview = false;
        } else {
            $app['session']->getFlashBag()->add('error', Trans::__("The folder '%s' could not be found, or is not readable.", array('%s' => $path)));
            $formview = false;
            $validFolder = false;
        }

        if ($validFolder) {
            // Define the "Upload here" form.
            $form = $app['form.factory']
                ->createBuilder('form')
                ->add(
                    'FileUpload',
                    'file',
                    array(
                        'label'    => Trans::__('Upload a file to this folder'),
                        'multiple' => true,
                        'attr'     => array(
                        'data-filename-placement' => 'inside',
                        'title'                   => Trans::__('Select file …'))
                    )
                )
                ->getForm();

            // Handle the upload.
            if ($request->isMethod('POST')) {
                $form->submit($request);
                if ($form->isValid()) {
                    $files = $request->files->get($form->getName());
                    $files = $files['FileUpload'];

                    foreach ($files as $fileToProcess) {
                        $fileToProcess = array(
                            'name'     => $fileToProcess->getClientOriginalName(),
                            'tmp_name' => $fileToProcess->getPathName()
                        );

                        $originalFilename = $fileToProcess['name'];
                        $filename = preg_replace('/[^a-zA-Z0-9_\\.]/', '_', basename($originalFilename));

                        if ($app['filepermissions']->allowedUpload($filename)) {
                            $app['upload.namespace'] = $namespace;
                            $handler = $app['upload'];
                            $handler->setPrefix($path . '/');
                            $result = $handler->process($fileToProcess);

                            if ($result->isValid()) {
                                $app['session']->getFlashBag()->add(
                                    'info',
                                    Trans::__("File '%file%' was uploaded successfully.", array('%file%' => $filename))
                                );

                                // Add the file to our stack.
                                $app['stack']->add($path . '/' . $filename);
                                $result->confirm();
                            } else {
                                foreach ($result->getMessages() as $message) {
                                    $app['session']->getFlashBag()->add(
                                        'error',
                                        $message->__toString()
                                    );
                                }
                            }
                        } else {
                            $extensionList = array();
                            foreach ($app['filepermissions']->getAllowedUploadExtensions() as $extension) {
                                $extensionList[] = '<code>.' . htmlspecialchars($extension, ENT_QUOTES) . '</code>';
                            }
                            $extensionList = implode(' ', $extensionList);
                            $app['session']->getFlashBag()->add(
                                'error',
                                Trans::__("File '%file%' could not be uploaded (wrong/disallowed file type). Make sure the file extension is one of the following:", array('%file%' => $filename))
                                . $extensionList
                            );
                        }
                    }
                } else {
                    $app['session']->getFlashBag()->add(
                        'error',
                        Trans::__("File '%file%' could not be uploaded.", array('%file%' => $filename))
                    );
                }

                return Lib::redirect('files', array('path' => $path, 'namespace' => $namespace));
            }

            if ($uploadview !== false) {
                $formview = $form->createView();
            }

            list($files, $folders) = $filesystem->browse($path, $app);
        }

        // Get the pathsegments, so we can show the path as breadcrumb navigation.
        $pathsegments = array();
        $cumulative = '';
        if (!empty($path)) {
            foreach (explode('/', $path) as $segment) {
                $cumulative .= $segment . '/';
                $pathsegments[$cumulative] = $segment;
            }
        }

        // Select the correct template to render this. If we've got 'CKEditor' in the title, it's a dialog
        // from CKeditor to insert a file.
        if (!$request->query->has('CKEditor')) {
            $twig = 'files/files.twig';
        } else {
            $app['debugbar'] = false;
            $twig = 'files_ck/files_ck.twig';
        }

        $context = array(
            'path'         => $path,
            'files'        => $files,
            'folders'      => $folders,
            'pathsegments' => $pathsegments,
            'form'         => $formview,
            'namespace'    => $namespace,
        );

        return $app['render']->render($twig, array('context' => $context));
    }

    /**
     * File editor.
     *
     * @param string      $namespace The filesystem namespace
     * @param string      $file      The file path
     * @param Application $app       The application/container
     * @param Request     $request   The Symfony Request
     *
     * @return \Twig_Markup|\Symfony\Component\HttpFoundation\RedirectResponse
     */
    public function fileEdit($namespace, $file, Application $app, Request $request)
    {
        if ($namespace == 'app' && dirname($file) == 'config') {
            // Special case: If requesting one of the major config files, like contenttypes.yml, set the path to the
            // correct dir, which might be 'app/config', but it might be something else.
            $namespace = 'config';
        }

        /** @var \League\Flysystem\FilesystemInterface $filesystem */
        $filesystem = $app['filesystem']->getFilesystem($namespace);

        if (!$filesystem->authorized($file)) {
            $error = Trans::__("You don't have correct permissions to edit the file '%s'.", array('%s' => $file));
            $app->abort(Response::HTTP_FORBIDDEN, $error);
        }

        /** @var \League\Flysystem\File $file */
        $file = $filesystem->get($file);
        $datechanged = date_format(new \DateTime('@' . $file->getTimestamp()), 'c');
        $type = Lib::getExtension($file->getPath());

        // Get the pathsegments, so we can show the path.
        $path = dirname($file->getPath());
        $pathsegments = array();
        $cumulative = '';
        if (!empty($path)) {
            foreach (explode('/', $path) as $segment) {
                $cumulative .= $segment . '/';
                $pathsegments[$cumulative] = $segment;
            }
        }

        $contents = null;
        if (!$file->exists() || !($contents = $file->read())) {
            $error = Trans::__("The file '%s' doesn't exist, or is not readable.", array('%s' => $file->getPath()));
            $app->abort(Response::HTTP_NOT_FOUND, $error);
        }

        if (!$file->update($contents)) {
            $app['session']->getFlashBag()->add(
                'info',
                Trans::__(
                    "The file '%s' is not writable. You will have to use your own editor to make modifications to this file.",
                    array('%s' => $file->getPath())
                )
            );
            $writeallowed = false;
        } else {
            $writeallowed = true;
        }

        // Gather the 'similar' files, if present.. i.e., if we're editing config.yml, we also want to check for
        // config.yml.dist and config_local.yml
        $basename = str_replace('.yml', '', str_replace('_local', '', $file->getPath()));
        $filegroup = array();
        if ($filesystem->has($basename . '.yml')) {
            $filegroup[] = basename($basename . '.yml');
        }
        if ($filesystem->has($basename . '_local.yml')) {
            $filegroup[] = basename($basename . '_local.yml');
        }

        $data = array('contents' => $contents);

        /** @var Form $form */
        $form = $app['form.factory']
            ->createBuilder('form', $data)
            ->add('contents', 'textarea')
            ->getForm();

        // Check if the form was POST-ed, and valid. If so, store the user.
        if ($request->isMethod('POST')) {
            $form->submit($app['request']->get($form->getName()));

            if ($form->isValid()) {
                $data = $form->getData();
                $contents = Input::cleanPostedData($data['contents']) . "\n";

                $result = array('ok' => true, 'msg' => 'Unhandled state.');

                // Before trying to save a yaml file, check if it's valid.
                if ($type === 'yml') {
                    $yamlparser = new Yaml\Parser();
                    try {
                        $yamlparser->parse($contents);
                    } catch (ParseException $e) {
                        $result['ok'] = false;
                        $result['msg'] = Trans::__("File '%s' could not be saved:", array('%s' => $file->getPath())) . $e->getMessage();
                    }
                }

                if ($result['ok']) {
                    // Remove ^M (or \r) characters from the file.
                    $contents = str_ireplace("\x0D", '', $contents);
                    if ($file->update($contents)) {
                        $result['msg'] = Trans::__("File '%s' has been saved.", array('%s' => $file->getPath()));
                        $result['datechanged'] = date_format(new \DateTime('@' . $file->getTimestamp()), 'c');
                    } else {
                        $result['msg'] = Trans::__("File '%s' could not be saved, for some reason.", array('%s' => $file->getPath()));
                    }
                }
            } else {
                $result = array(
                    'ok' => false,
                    'msg' => Trans::__("File '%s' could not be saved, because the form wasn't valid.", array('%s' => $file->getPath()))
                );
            }

            return new JsonResponse($result);
        }

        // For 'related' files we might need to keep track of the current dirname on top of the namespace.
        if (dirname($file->getPath()) != '') {
            $additionalpath = dirname($file->getPath()) . '/';
        } else {
            $additionalpath = '';
        }

        $context = array(
            'form'           => $form->createView(),
            'filetype'       => $type,
            'file'           => $file->getPath(),
            'basename'       => basename($file->getPath()),
            'pathsegments'   => $pathsegments,
            'additionalpath' => $additionalpath,
            'namespace'      => $namespace,
            'write_allowed'  => $writeallowed,
            'filegroup'      => $filegroup,
            'datechanged'    => $datechanged
        );

        return $app['render']->render('editfile/editfile.twig', array('context' => $context));
    }

    /**
     * Middleware function to check whether a user is logged on.
     *
     * @param Request     $request The Symfony Request
     * @param Application $app     The application/container
     *
     * @return null|\Symfony\Component\HttpFoundation\RedirectResponse
     */
    public static function before(Request $request, Application $app)
    {
        // Start the 'stopwatch' for the profiler.
        $app['stopwatch']->start('bolt.backend.before');

        $route = $request->get('_route');

        $app['debugbar'] = true;

        // Sanity checks for doubles in in contenttypes.
        // unfortunately this has to be done here, because the 'translator' classes need to be initialised.
        $app['config']->checkConfig();

        // If we had to reload the config earlier on because we detected a version change, display a notice.
        if ($app['config']->notify_update) {
            $notice = Trans::__("Detected Bolt version change to <b>%VERSION%</b>, and the cache has been cleared. Please <a href=\"%URI%\">check the database</a>, if you haven't done so already.",
                array('%VERSION%' => $app->getVersion(), '%URI%' => $app['resources']->getUrl('bolt') . 'dbcheck'));
            $app['logger.system']->notice(strip_tags($notice), array('event' => 'config'));
            $app['session']->getFlashBag()->add('info', $notice);
        }

        // Check the database users table exists
        $tableExists = $app['integritychecker']->checkUserTableIntegrity();

        // Test if we have a valid users in our table
        $hasUsers = false;
        if ($tableExists) {
            $hasUsers = $app['users']->hasUsers();
        }

        // If the users table is present, but there are no users, and we're on /bolt/userfirst,
        // we let the user stay, because they need to set up the first user.
        if ($tableExists && !$hasUsers && $route == 'userfirst') {
            return null;
        }

        // If there are no users in the users table, or the table doesn't exist. Repair
        // the DB, and let's add a new user.
        if (!$tableExists || !$hasUsers) {
            $app['integritychecker']->repairTables();
            $app['session']->getFlashBag()->add('info', Trans::__('There are no users in the database. Please create the first user.'));

            return Lib::redirect('userfirst');
        }

        // Confirm the user is enabled or bounce them
        if ($app['users']->getCurrentUser() && !$app['users']->isEnabled() && $route !== 'userfirst' && $route !== 'login' && $route !== 'postLogin' && $route !== 'logout') {
            $app['session']->getFlashBag()->add('error', Trans::__('Your account is disabled. Sorry about that.'));

            return Lib::redirect('logout');
        }

        // Check if there's at least one 'root' user, and otherwise promote the current user.
        $app['users']->checkForRoot();

        // Most of the 'check if user is allowed' happens here: match the current route to the 'allowed' settings.
        if (!$app['users']->isValidSession() && !$app['users']->isAllowed($route)) {
            $app['session']->getFlashBag()->add('info', Trans::__('Please log on.'));

            return Lib::redirect('login');
        } elseif (!$app['users']->isAllowed($route)) {
            $app['session']->getFlashBag()->add('error', Trans::__('You do not have the right privileges to view that page.'));

            return Lib::redirect('dashboard');
        }

        // Stop the 'stopwatch' for the profiler.
        $app['stopwatch']->stop('bolt.backend.before');

        return null;
    }

    /**
     * Create a user form with the form builder.
     *
     * @param Application $app
     * @param array       $user
     * @param boolean     $editusername
     *
     * @return \Symfony\Component\Form\FormBuilder
     */
    private function getUserForm(Application $app, array $user, $editusername = false)
    {
        // Start building the form
        $form = $app['form.factory']->createBuilder('form', $user);

        // Username goes first (editable when not viewing own profile)
        if ($editusername) {
            $form->add(
                'username',
                'text',
                array(
                    'constraints' => array(new Assert\NotBlank(), new Assert\Length(array('min' => 2, 'max' => 32))),
                    'label'       => Trans::__('page.edit-users.label.username'),
                    'attr'        => array(
                        'placeholder' => Trans::__('page.edit-users.placeholder.username')
                    )
                )
            );
        } else {
            $form->add(
                'username',
                'text',
                array(
                    'constraints' => array(new Assert\NotBlank(), new Assert\Length(array('min' => 2, 'max' => 32))),
                    'label'       => Trans::__('page.edit-users.label.username'),
                    'attr'        => array(
                        'placeholder' => Trans::__('page.edit-users.placeholder.username')
                    ),
                    'read_only'   => true
                )
            );
        }


        // Add the other fields
        $form
            ->add('id', 'hidden')
            ->add(
                'password',
                'password',
                array(
                    'required' => false,
                    'label'    => Trans::__('page.edit-users.label.password'),
                    'attr'     => array(
                        'placeholder' => Trans::__('page.edit-users.placeholder.password')
                    )
                )
            )
            ->add(
                'password_confirmation',
                'password',
                array(
                    'required' => false,
                    'label'    => Trans::__('page.edit-users.label.password-confirm'),
                    'attr'     => array(
                        'placeholder' => Trans::__('page.edit-users.placeholder.password-confirm')
                    )
                )
            )
            ->add(
                'email',
                'text',
                array(
                    'constraints' => new Assert\Email(),
                    'label'       => Trans::__('page.edit-users.label.email'),
                    'attr'        => array(
                        'placeholder' => Trans::__('page.edit-users.placeholder.email')
                    )
                )
            )
            ->add(
                'displayname',
                'text',
                array(
                    'constraints' => array(new Assert\NotBlank(), new Assert\Length(array('min' => 2, 'max' => 32))),
                    'label'       => Trans::__('page.edit-users.label.display-name'),
                    'attr'        => array(
                        'placeholder' => Trans::__('page.edit-users.placeholder.displayname')
                    )
                )
            );

        return $form;
    }

    /**
     * Validate the user form.
     *
     * Use a custom validator to check:
     *   * Passwords are identical
     *   * Username is unique
     *   * Email is unique
     *   * Displaynames are unique
     *
     * @param Application                         $app
     * @param \Symfony\Component\Form\FormBuilder $form
     * @param boolean                             $addusername
     *
     * @return \Symfony\Component\Form\FormBuilder
     */
    private function setUserFormValidation(Application $app, FormBuilder $form, $addusername = false)
    {
        $form->addEventListener(
            FormEvents::POST_SUBMIT,
            function (FormEvent $event) use ($app, $addusername) {
                $form = $event->getForm();
                $id = $form['id']->getData();
                $pass1 = $form['password']->getData();
                $pass2 = $form['password_confirmation']->getData();

                // If adding a new user (empty $id) or if the password is not empty (indicating we want to change it),
                // then make sure it's at least 6 characters long.
                if ((empty($id) || !empty($pass1)) && strlen($pass1) < 6) {
                    // screw it. Let's just not translate this message for now. Damn you, stupid non-cooperative
                    // translation thingy. $error = new FormError("This value is too short. It should have {{ limit }}
                    // characters or more.", array('{{ limit }}' => 6), 2);
                    $error = new FormError(Trans::__('page.edit-users.error.password-short'));
                    $form['password']->addError($error);
                }

                // Passwords must be identical.
                if ($pass1 != $pass2) {
                    $form['password_confirmation']->addError(new FormError(Trans::__('page.edit-users.error.password-mismatch')));
                }

                if ($addusername) {
                    // Usernames must be unique.
                    if (!$app['users']->checkAvailability('username', $form['username']->getData(), $id)) {
                        $form['username']->addError(new FormError(Trans::__('page.edit-users.error.username-used')));
                    }
                }

                // Issue 3491 : Password must be different from username
                $username = $form['username']->getData();
                if (!empty($username) && $pass1 === $username) {
                    $form['password']->addError(new FormError(Trans::__('page.edit-users.error.password-different-username')));
                }

                // Email addresses must be unique.
                if (!$app['users']->checkAvailability('email', $form['email']->getData(), $id)) {
                    $form['email']->addError(new FormError(Trans::__('page.edit-users.error.email-used')));
                }

                // Displaynames must be unique.
                if (!$app['users']->checkAvailability('displayname', $form['displayname']->getData(), $id)) {
                    $form['displayname']->addError(new FormError(Trans::__('page.edit-users.error.displayname-used')));
                }
            }
        );

        return $form;
    }
}
