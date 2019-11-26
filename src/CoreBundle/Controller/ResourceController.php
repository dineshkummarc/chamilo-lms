<?php
/* For licensing terms, see /license.txt */

namespace Chamilo\CoreBundle\Controller;

use APY\DataGridBundle\Grid\Action\MassAction;
use APY\DataGridBundle\Grid\Action\RowAction;
use APY\DataGridBundle\Grid\Export\CSVExport;
use APY\DataGridBundle\Grid\Export\ExcelExport;
use APY\DataGridBundle\Grid\Grid;
use APY\DataGridBundle\Grid\Row;
use APY\DataGridBundle\Grid\Source\Entity;
use Chamilo\CoreBundle\Component\Utils\Glide;
use Chamilo\CoreBundle\Entity\Resource\AbstractResource;
use Chamilo\CoreBundle\Entity\Resource\ResourceLink;
use Chamilo\CoreBundle\Entity\Resource\ResourceNode;
use Chamilo\CoreBundle\Security\Authorization\Voter\ResourceNodeVoter;
use Chamilo\CourseBundle\Controller\CourseControllerInterface;
use Chamilo\CourseBundle\Controller\CourseControllerTrait;
use Chamilo\CourseBundle\Entity\CDocument;
use Chamilo\CourseBundle\Repository\CDocumentRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Criteria;
use Doctrine\ORM\QueryBuilder;
use FOS\CKEditorBundle\Form\Type\CKEditorType;
use League\Flysystem\Filesystem;
use Symfony\Component\Filesystem\Exception\FileNotFoundException;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Routing\Router;
use Vich\UploaderBundle\Util\Transliterator;
use ZipStream\Option\Archive;
use ZipStream\ZipStream;

/**
 * Class ResourceController.
 *
 * @Route("/resources")
 *
 * @author Julio Montoya <gugli100@gmail.com>.
 */
class ResourceController extends AbstractResourceController implements CourseControllerInterface
{
    use CourseControllerTrait;

    /**
     * @Route("/{tool}/{type}", name="chamilo_core_resource_index")
     *
     * Example: /document/files
     * For the tool value check the Tool entity.
     * For the type value check the ResourceType entity.
     */
    public function indexAction(Request $request, Grid $grid): Response
    {
        $tool = $request->get('tool');
        $type = $request->get('type');

        $grid = $this->getGrid($request, $grid);

        $breadcrumb = $this->breadcrumbBlockService;
        $breadcrumb->addChild(
            $this->trans('Documents'),
            [
                'uri' => '#',
            ]
        );

        // The base resource node is the course.
        $id = $this->getCourse()->getResourceNode()->getId();

        return $grid->getGridResponse(
            '@ChamiloTheme/Resource/index.html.twig',
            ['tool' => $tool, 'type' => $type, 'id' => $id]
        );
    }

    /**
     * @param int $resourceNodeId
     *
     * @return Grid
     */
    public function getGrid(Request $request, Grid $grid, $resourceNodeId = 0)
    {
        $tool = $request->get('tool');
        $type = $request->get('type');
        $id = (int) $request->get('id');

        $repository = $this->getRepositoryFromRequest($request);
        $class = $repository->getRepository()->getClassName();
        $source = new Entity($class);

        $course = $this->getCourse();
        $session = $this->getSession();

        $parent = $course->getResourceNode();
        if (!empty($resourceNodeId)) {
            $parent = $repository->getResourceNodeRepository()->find($resourceNodeId);
        }

        $qb = $repository->getResourcesByCourse($course, $session, null, $parent);

        // 3. Set QueryBuilder to the source.
        $source->initQueryBuilder($qb);
        $grid->setSource($source);

        $grid->setRouteUrl(
            $this->generateUrl(
                'chamilo_core_resource_list',
                [
                    'tool' => $tool,
                    'type' => $type,
                    'cidReq' => $this->getCourse()->getCode(),
                    'id_session' => $this->getSessionId(),
                    'id' => $id,
                ]
            )
        );

        $title = $grid->getColumn('title');
        $title->setSafe(false); // allows links in the title

        //$grid->hideFilters();
        //$grid->setLimits(20);
        //$grid->isReadyForRedirect();
        //$grid->setMaxResults(1);
        //$grid->setLimits(2);

        $translation = $this->translator;
        $courseIdentifier = $course->getCode();

        $routeParams = [
            'tool' => $tool,
            'type' => $type,
            'cidReq' => $courseIdentifier,
            'id_session' => $this->getSessionId(),
            'id',
        ];

        // Title link.
        $grid->getColumn('title')->setTitle($this->trans('Name'));
        $grid->getColumn('filetype')->setTitle($this->trans('Type'));

        $grid->getColumn('title')->manipulateRenderCell(
            function ($value, Row $row, $router) use ($routeParams) {
                /** @var Router $router */
                /** @var CDocument $entity */
                $entity = $row->getEntity();
                $resourceNode = $entity->getResourceNode();
                $id = $resourceNode->getId();

                $myParams = $routeParams;
                $myParams['id'] = $id;
                unset($myParams[0]);

                $icon = $resourceNode->getIcon().' &nbsp;';
                if ($resourceNode->hasResourceFile()) {
                    /*$url = $router->generate(
                        'chamilo_core_resource_show',
                        $myParams
                    );*/

                    if ($resourceNode->isResourceFileAnImage()) {
                        $url = $router->generate(
                            'chamilo_core_resource_file',
                            $myParams
                        );

                        return $icon.'<a data-fancybox="gallery" href="'.$url.'">'.$value.'</a>';
                    }

                    if ($resourceNode->isResourceFileAVideo()) {
                        $url = $router->generate(
                            'chamilo_core_resource_file',
                            $myParams
                        );

                        return '
                        <video width="640" height="320" controls id="video'.$id.'" controls preload="metadata" style="display:none;">
                            <source src="'.$url.'" type="video/mp4">
                            Your browser doesn\'t support HTML5 video tag.
                        </video>
                        '.$icon.' <a data-fancybox="gallery"  data-width="640" data-height="360" href="#video'.$id.'">'.$value.'</a>';
                    }

                    $url = $router->generate(
                        'chamilo_core_resource_preview',
                        $myParams
                    );

                    return $icon.'<a data-fancybox="gallery" data-type="iframe" data-src="'.$url.'" href="javascript:;" >'.$value.'</a>';
                } else {
                    $url = $router->generate(
                        'chamilo_core_resource_list',
                        $myParams
                    );

                    return $icon.'<a href="'.$url.'">'.$value.'</a>';
                }
            }
        );

        $grid->getColumn('filetype')->manipulateRenderCell(
            function ($value, Row $row, $router) use ($routeParams) {
                /** @var CDocument $entity */
                $entity = $row->getEntity();
                $resourceNode = $entity->getResourceNode();

                if ($resourceNode->hasResourceFile()) {
                    $file = $resourceNode->getResourceFile();

                    return $file->getMimeType();
                }

                return $this->trans('Folder');
            }
        );

        $grid->setHiddenColumns(['iid']);

        // Delete mass action.
        if ($this->isGranted(ResourceNodeVoter::DELETE, $this->getCourse())) {
            $deleteMassAction = new MassAction(
                'Delete',
                'ChamiloCoreBundle:Resource:deleteMass',
                true,
                $routeParams
            );
            $grid->addMassAction($deleteMassAction);
        }

        // Show resource action.
        $myRowAction = new RowAction(
            $translation->trans('Info'),
            'chamilo_core_resource_show',
            false,
            '_self',
            [
                'class' => 'btn btn-secondary info_action',
                'icon' => 'fa-info-circle',
                'iframe' => true,
            ]
        );

        $setNodeParameters = function (RowAction $action, Row $row) use ($routeParams) {
            $id = $row->getEntity()->getResourceNode()->getId();
            $routeParams['id'] = $id;
            $action->setRouteParameters($routeParams);
            $attributes = $action->getAttributes();
            $attributes['data-action'] = $action->getRoute();
            $attributes['data-action-id'] = $action->getRoute().'_'.$id;
            $attributes['data-node-id'] = $id;

            $action->setAttributes($attributes);

            return $action;
        };

        $myRowAction->addManipulateRender($setNodeParameters);
        $grid->addRowAction($myRowAction);

        if ($this->isGranted(ResourceNodeVoter::EDIT, $this->getCourse())) {
            // Enable/Disable
            $myRowAction = new RowAction(
                '',
                'chamilo_core_resource_change_visibility',
                false,
                '_self'
            );

            $setVisibleParameters = function (RowAction $action, Row $row) use ($routeParams) {
                /** @var CDocument $resource */
                $resource = $row->getEntity();
                $id = $resource->getResourceNode()->getId();

                $icon = 'fa-eye-slash';
                $link = $resource->getCourseSessionResourceLink($this->getCourse(), $this->getSession());

                if ($link === null) {
                    return null;
                }
                if ($link->getVisibility() === ResourceLink::VISIBILITY_PUBLISHED) {
                    $icon = 'fa-eye';
                }
                $routeParams['id'] = $id;
                $action->setRouteParameters($routeParams);
                $attributes = [
                    'class' => 'btn btn-secondary change_visibility',
                    'data-id' => $id,
                    'icon' => $icon,
                ];
                $action->setAttributes($attributes);

                return $action;
            };

            $myRowAction->addManipulateRender($setVisibleParameters);
            $grid->addRowAction($myRowAction);

            // Edit action.
            $myRowAction = new RowAction(
                $translation->trans('Edit'),
                'chamilo_core_resource_edit',
                false,
                '_self',
                ['class' => 'btn btn-secondary', 'icon' => 'fa fa-pen']
            );
            $myRowAction->addManipulateRender($setNodeParameters);
            $grid->addRowAction($myRowAction);

            // More action.
            $myRowAction = new RowAction(
                $translation->trans('More'),
                'chamilo_core_resource_preview',
                false,
                '_self',
                ['class' => 'btn btn-secondary edit_resource', 'icon' => 'fa fa-ellipsis-h']
            );

            $myRowAction->addManipulateRender($setNodeParameters);
            $grid->addRowAction($myRowAction);

            // Delete action.
            $myRowAction = new RowAction(
                $translation->trans('Delete'),
                'chamilo_core_resource_delete',
                true,
                '_self',
                ['class' => 'btn btn-danger', 'data_hidden' => true]
            );
            $myRowAction->addManipulateRender($setNodeParameters);
            $grid->addRowAction($myRowAction);
        }

        /*$grid->addExport(new CSVExport($translation->trans('CSV export'), 'export', ['course' => $courseIdentifier]));
        $grid->addExport(
            new ExcelExport(
                $translation->trans('Excel export'),
                'export',
                ['course' => $courseIdentifier]
            )
        );*/

        return $grid;
    }

    /**
     * @Route("/{tool}/{type}/{id}/list", name="chamilo_core_resource_list")
     *
     * If node has children show it
     */
    public function listAction(Request $request, Grid $grid): Response
    {
        $tool = $request->get('tool');
        $type = $request->get('type');
        $resourceNodeId = $request->get('id');

        $grid = $this->getGrid($request, $grid, $resourceNodeId);

        $this->setBreadCrumb($request);

        return $grid->getGridResponse(
            '@ChamiloTheme/Resource/index.html.twig',
            ['parent_id' => $resourceNodeId, 'tool' => $tool, 'type' => $type, 'id' => $resourceNodeId]
        );
    }

    /**
     * @Route("/{tool}/{type}/{id}/new_folder", methods={"GET", "POST"}, name="chamilo_core_resource_new_folder")
     */
    public function newFolderAction(Request $request): Response
    {
        $this->setBreadCrumb($request);

        return $this->createResource($request, 'folder');
    }

    /**
     * @Route("/{tool}/{type}/{id}/new", methods={"GET", "POST"}, name="chamilo_core_resource_new")
     */
    public function newAction(Request $request): Response
    {
        $this->setBreadCrumb($request);

        return $this->createResource($request, 'file');
    }

    /**
     * @Route("/{tool}/{type}/{id}/edit", methods={"GET", "POST"})
     */
    public function editAction(Request $request): Response
    {
        $tool = $request->get('tool');
        $type = $request->get('type');
        $resourceNodeId = $request->get('id');

        $this->setBreadCrumb($request);
        $repository = $this->getRepositoryFromRequest($request);
        /** @var AbstractResource $resource */
        $resource = $repository->getRepository()->findOneBy(['resourceNode' => $resourceNodeId]);
        $resourceNode = $resource->getResourceNode();

        $this->denyAccessUnlessGranted(
            ResourceNodeVoter::EDIT,
            $resourceNode,
            $this->trans('Unauthorised access to resource')
        );

        $resourceNodeParentId = $resourceNode->getId();

        $form = $repository->getForm($this->container->get('form.factory'), $resource);

        if ($resourceNode->isEditable()) {
            $form->add(
                'content',
                CKEditorType::class,
                [
                    'mapped' => false,
                    'config' => [
                        'filebrowserImageBrowseRoute' => 'editor_filemanager',
                        'filebrowserImageBrowseRouteParameters' => [
                            'tool' => $tool,
                            'type' => $type,
                            'cidReq' => $this->getCourse()->getCode(),
                            'id_session' => $this->getSessionId(),
                            'id' => $resourceNodeParentId,
                        ],
                    ],
                ]
            );
            $content = $repository->getResourceFileContent($resource);
            $form->get('content')->setData($content);
        }

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var CDocument $newResource */
            $newResource = $form->getData();

            if ($form->has('content')) {
                $data = $form->get('content')->getData();
                $repository->updateResourceFileContent($newResource, $data);
            }

            $newResource->setTitle($form->get('title')->getData());
            $repository->updateNodeForResource($newResource);

            /*$em->persist($newResource);
            $em->flush();*/

            $this->addFlash('success', $this->trans('Updated'));

            if ($newResource->getResourceNode()->hasResourceFile()) {
                $resourceNodeParentId = $newResource->getResourceNode()->getParent()->getId();
            }

            return $this->redirectToRoute(
                'chamilo_core_resource_list',
                [
                    'id' => $resourceNodeParentId,
                    'tool' => $tool,
                    'type' => $type,
                    'cidReq' => $this->getCourse()->getCode(),
                    'id_session' => $this->getSessionId(),
                ]
            );
        }

        return $this->render(
            '@ChamiloTheme/Resource/edit.html.twig',
            [
                'form' => $form->createView(),
                'parent' => $resourceNodeParentId,
            ]
        );
    }

    /**
     * Shows a resource information.
     *
     * @Route("/{tool}/{type}/{id}/show", methods={"GET"}, name="chamilo_core_resource_show")
     */
    public function showAction(Request $request): Response
    {
        $this->setBreadCrumb($request);
        $nodeId = $request->get('id');

        $repository = $this->getRepositoryFromRequest($request);

        /** @var AbstractResource $resource */
        $resource = $repository->getRepository()->findOneBy(['resourceNode' => $nodeId]);

        if (null === $resource) {
            throw new NotFoundHttpException();
        }

        $resourceNode = $resource->getResourceNode();

        if (null === $resourceNode) {
            throw new NotFoundHttpException();
        }

        $this->denyAccessUnlessGranted(
            ResourceNodeVoter::VIEW,
            $resourceNode,
            $this->trans('Unauthorised access to resource')
        );

        $tool = $request->get('tool');
        $type = $request->get('type');

        $params = [
            'resource' => $resource,
            'tool' => $tool,
            'type' => $type,
        ];

        return $this->render('@ChamiloTheme/Resource/show.html.twig', $params);
    }

    /**
     * Preview a file. Mostly used when using a modal.
     *
     * @Route("/{tool}/{type}/{id}/preview", methods={"GET"}, name="chamilo_core_resource_preview")
     */
    public function previewAction(Request $request): Response
    {
        $this->setBreadCrumb($request);
        $nodeId = $request->get('id');

        $repository = $this->getRepositoryFromRequest($request);

        /** @var AbstractResource $resource */
        $resource = $repository->getRepository()->findOneBy(['resourceNode' => $nodeId]);

        if (null === $resource) {
            throw new NotFoundHttpException();
        }

        $resourceNode = $resource->getResourceNode();

        if (null === $resourceNode) {
            throw new NotFoundHttpException();
        }

        $this->denyAccessUnlessGranted(
            ResourceNodeVoter::VIEW,
            $resourceNode,
            $this->trans('Unauthorised access to resource')
        );

        $tool = $request->get('tool');
        $type = $request->get('type');

        $params = [
            'resource' => $resource,
            'tool' => $tool,
            'type' => $type,
        ];

        return $this->render('@ChamiloTheme/Resource/preview.html.twig', $params);
    }

    /**
     * @Route("/{tool}/{type}/{id}/change_visibility", name="chamilo_core_resource_change_visibility")
     */
    public function changeVisibilityAction(Request $request): Response
    {
        $id = $request->get('id');

        $repository = $this->getRepositoryFromRequest($request);

        /** @var AbstractResource $resource */
        $resource = $repository->getRepository()->findOneBy(['resourceNode' => $id]);

        if (null === $resource) {
            throw new NotFoundHttpException();
        }

        $resourceNode = $resource->getResourceNode();

        $this->denyAccessUnlessGranted(
            ResourceNodeVoter::EDIT,
            $resourceNode,
            $this->trans('Unauthorised access to resource')
        );

        /** @var ResourceLink $link */
        $link = $resource->getCourseSessionResourceLink($this->getCourse(), $this->getSession());

        $icon = 'fa-eye';
        // Use repository to change settings easily.
        if ($link && $link->getVisibility() === ResourceLink::VISIBILITY_PUBLISHED) {
            $repository->setVisibilityDraft($resource);
            $icon = 'fa-eye-slash';
        } else {
            $repository->setVisibilityPublished($resource);
        }

        $result = ['icon' => $icon];

        return new JsonResponse($result);
    }

    /**
     * @Route("/{tool}/{type}/{id}", name="chamilo_core_resource_delete")
     */
    public function deleteAction(Request $request): Response
    {
        $tool = $request->get('tool');
        $type = $request->get('type');

        $em = $this->getDoctrine()->getManager();

        $id = $request->get('id');
        $resourceNode = $this->getDoctrine()->getRepository('ChamiloCoreBundle:Resource\ResourceNode')->find($id);
        $parentId = $resourceNode->getParent()->getId();

        if (null === $resourceNode) {
            throw new NotFoundHttpException();
        }

        $this->denyAccessUnlessGranted(
            ResourceNodeVoter::DELETE,
            $resourceNode,
            $this->trans('Unauthorised access to resource')
        );

        $em->remove($resourceNode);
        $this->addFlash('success', $this->trans('Deleted'));
        $em->flush();

        return $this->redirectToRoute(
            'chamilo_core_resource_list',
            [
                'id' => $parentId,
                'tool' => $tool,
                'type' => $type,
                'cidReq' => $this->getCourse()->getCode(),
                'id_session' => $this->getSessionId(),
            ]
        );
    }

    /**
     * @Route("/{tool}/{type}/{id}", methods={"DELETE"}, name="chamilo_core_resource_delete_mass")
     */
    public function deleteMassAction($primaryKeys, $allPrimaryKeys, Request $request): Response
    {
        $tool = $request->get('tool');
        $type = $request->get('type');
        $em = $this->getDoctrine()->getManager();
        $repo = $this->getRepositoryFromRequest($request);

        $parentId = 0;
        foreach ($primaryKeys as $id) {
            $resource = $repo->find($id);
            $resourceNode = $resource->getResourceNode();

            if (null === $resourceNode) {
                continue;
            }

            $this->denyAccessUnlessGranted(
                ResourceNodeVoter::DELETE,
                $resourceNode,
                $this->trans('Unauthorised access to resource')
            );

            $parentId = $resourceNode->getParent()->getId();
            $em->remove($resource);
        }

        $this->addFlash('success', $this->trans('Deleted'));
        $em->flush();

        return $this->redirectToRoute(
            'chamilo_core_resource_list',
            [
                'id' => $parentId,
                'tool' => $tool,
                'type' => $type,
                'cidReq' => $this->getCourse()->getCode(),
                'id_session' => $this->getSessionId(),
            ]
        );
    }

    /**
     * @Route("/{tool}/{type}/{id}/file", methods={"GET"}, name="chamilo_core_resource_file")
     */
    public function getResourceFileAction(Request $request, Glide $glide): Response
    {
        $id = $request->get('id');
        $filter = $request->get('filter');
        $mode = $request->get('mode');
        $em = $this->getDoctrine();
        $resourceNode = $em->getRepository('ChamiloCoreBundle:Resource\ResourceNode')->find($id);

        if ($resourceNode === null) {
            throw new FileNotFoundException('Resource not found');
        }

        return $this->showFile($request, $resourceNode, $glide, $mode, $filter);
    }

    /**
     * Gets a document when calling route resources_document_get_file.
     *
     * @param CDocumentRepository $documentRepo
     *
     * @throws \League\Flysystem\FileNotFoundException
     */
    public function getDocumentAction(Request $request, Glide $glide): Response
    {
        $file = $request->get('file');
        $mode = $request->get('mode');

        // see list of filters in config/services.yaml
        $filter = $request->get('filter');
        $mode = !empty($mode) ? $mode : 'show';

        $repository = $this->getRepository('document', 'files');
        $nodeRepository = $repository->getResourceNodeRepository();

        $title = basename($file);
        // @todo improve criteria to avoid giving the wrong file.
        $criteria = ['slug' => $title];

        $resourceNode = $nodeRepository->findOneBy($criteria);

        if (null === $resourceNode) {
            throw new NotFoundHttpException();
        }

        $this->denyAccessUnlessGranted(
            ResourceNodeVoter::VIEW,
            $resourceNode,
            $this->trans('Unauthorised access to resource')
        );

        return $this->showFile($request, $resourceNode, $glide, $mode, $filter);
    }

    /**
     * Downloads a folder.
     *
     * @return Response
     */
    public function downloadFolderAction(Request $request, CDocumentRepository $documentRepo)
    {
        $folderId = (int) $request->get('folderId');
        $courseNode = $this->getCourse()->getResourceNode();

        if (empty($folderId)) {
            $resourceNode = $courseNode;
        } else {
            $document = $documentRepo->find($folderId);
            $resourceNode = $document->getResourceNode();
        }

        $type = $documentRepo->getResourceType();

        if (null === $resourceNode || null === $courseNode) {
            throw new NotFoundHttpException();
        }

        $this->denyAccessUnlessGranted(
            ResourceNodeVoter::VIEW,
            $resourceNode,
            $this->trans('Unauthorised access to resource')
        );

        $zipName = $resourceNode->getName().'.zip';
        $rootNodePath = $resourceNode->getPathForDisplay();

        /** @var Filesystem $fileSystem */
        $fileSystem = $this->get('oneup_flysystem.resources_filesystem');

        $resourceNodeRepo = $documentRepo->getResourceNodeRepository();

        $criteria = Criteria::create()
            ->where(Criteria::expr()->neq('resourceFile', null))
            ->andWhere(Criteria::expr()->eq('resourceType', $type))
        ;

        /** @var ArrayCollection|ResourceNode[] $children */
        /** @var QueryBuilder $children */
        $qb = $resourceNodeRepo->getChildrenQueryBuilder($resourceNode);
        $qb->addCriteria($criteria);
        $children = $qb->getQuery()->getResult();

        /** @var ResourceNode $node */
        foreach ($children as $node) {
            /*if ($node->hasResourceFile()) {
                $resourceFile = $node->getResourceFile();
                $systemName = $resourceFile->getFile()->getPathname();
                $stream = $fileSystem->readStream($systemName);
                //error_log($node->getPathForDisplay());
                $fileToDisplay = str_replace($rootNodePath, '', $node->getPathForDisplay());
                var_dump($fileToDisplay);
            }*/
            var_dump($node->getPathForDisplay());
            //var_dump($node['path']);
        }

        exit;

        $response = new StreamedResponse(function () use ($rootNodePath, $zipName, $children, $fileSystem) {
            // Define suitable options for ZipStream Archive.
            $options = new Archive();
            $options->setContentType('application/octet-stream');
            //initialise zipstream with output zip filename and options.
            $zip = new ZipStream($zipName, $options);

            /** @var ResourceNode $node */
            foreach ($children as $node) {
                $resourceFile = $node->getResourceFile();
                $systemName = $resourceFile->getFile()->getPathname();
                $stream = $fileSystem->readStream($systemName);
                //error_log($node->getPathForDisplay());
                $fileToDisplay = str_replace($rootNodePath, '', $node->getPathForDisplay());
                $zip->addFileFromStream($fileToDisplay, $stream);
            }
            //$data = $repo->getDocumentContent($not_deleted_file['id']);
            //$zip->addFile($not_deleted_file['path'], $data);
            $zip->finish();
        });

        $disposition = $response->headers->makeDisposition(
            ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            Transliterator::transliterate($zipName)
        );
        $response->headers->set('Content-Disposition', $disposition);
        $response->headers->set('Content-Type', 'application/octet-stream');

        return $response;
    }

    /**
     * Upload form.
     *
     * @Route("/{tool}/{type}/{id}/upload", name="chamilo_core_resource_upload", methods={"GET", "POST"},
     *                                      options={"expose"=true})
     */
    public function uploadAction(Request $request, $tool, $type, $id): Response
    {
        $repository = $this->getRepositoryFromRequest($request);
        $resourceNode = $repository->getResourceNodeRepository()->find($id);

        $this->denyAccessUnlessGranted(
            ResourceNodeVoter::EDIT,
            $resourceNode,
            $this->trans('Unauthorised access to resource')
        );

        $this->setBreadCrumb($request);
        //$helper = $this->container->get('oneup_uploader.templating.uploader_helper');
        //$endpoint = $helper->endpoint('courses');

        return $this->render(
            '@ChamiloTheme/Resource/upload.html.twig',
            [
                'id' => $id,
                'type' => $type,
                'tool' => $tool,
                'cidReq' => $this->getCourse()->getCode(),
                'id_session' => $this->getSessionId(),
            ]
        );
    }

    public function setBreadCrumb(Request $request)
    {
        $tool = $request->get('tool');
        $type = $request->get('type');
        $resourceNodeId = $request->get('id');
        $courseCode = $request->get('cidReq');
        $sessionId = $request->get('id_session');

        if (!empty($resourceNodeId)) {
            $breadcrumb = $this->breadcrumbBlockService;

            // Root tool link
            $breadcrumb->addChild(
                $this->translator->trans('Documents'),
                [
                    'uri' => $this->generateUrl(
                        'chamilo_core_resource_index',
                        ['tool' => $tool, 'type' => $type, 'cidReq' => $courseCode, 'id_session' => $sessionId]
                    ),
                ]
            );

            $repo = $this->getRepositoryFromRequest($request);

            /** @var AbstractResource $parent */
            $originalResource = $repo->findOneBy(['resourceNode' => $resourceNodeId]);
            if ($originalResource === null) {
                return;
            }
            $parent = $originalParent = $originalResource->getResourceNode();

            $parentList = [];
            while ($parent !== null) {
                if ($type !== $parent->getResourceType()->getName()) {
                    break;
                }
                $parent = $parent->getParent();
                if ($parent) {
                    $resource = $repo->findOneBy(['resourceNode' => $parent->getId()]);
                    if ($resource) {
                        $parentList[] = $resource;
                    }
                }
            }

            $parentList = array_reverse($parentList);
            /** @var AbstractResource $item */
            foreach ($parentList as $item) {
                $breadcrumb->addChild(
                    $item->getResourceName(),
                    [
                        'uri' => $this->generateUrl(
                            'chamilo_core_resource_list',
                            [
                                'tool' => $tool,
                                'type' => $type,
                                'id' => $item->getResourceNode()->getId(),
                                'cidReq' => $courseCode,
                                'id_session' => $sessionId,
                            ]
                        ),
                    ]
                );
            }

            $breadcrumb->addChild(
                $originalResource->getResourceName(),
                [
                    'uri' => $this->generateUrl(
                        'chamilo_core_resource_list',
                        [
                            'tool' => $tool,
                            'type' => $type,
                            'id' => $originalParent->getId(),
                            'cidReq' => $courseCode,
                            'id_session' => $sessionId,
                        ]
                    ),
                ]
            );
        }
    }

    /**
     * @param string $mode   show or download
     * @param string $filter
     *
     * @return mixed|StreamedResponse
     */
    private function showFile(Request $request, ResourceNode $resourceNode, Glide $glide, $mode = 'show', $filter = '')
    {
        $this->denyAccessUnlessGranted(
            ResourceNodeVoter::VIEW,
            $resourceNode,
            $this->trans('Unauthorised access to resource')
        );
        $resourceFile = $resourceNode->getResourceFile();

        if (!$resourceFile) {
            throw new NotFoundHttpException();
        }

        //$fileName = $resourceFile->getOriginalName();
        $fileName = $resourceNode->getSlug();
        $filePath = $resourceFile->getFile()->getPathname();
        $mimeType = $resourceFile->getMimeType();

        switch ($mode) {
            case 'download':
                $forceDownload = true;
                break;
            case 'show':
            default:
                $forceDownload = false;
                // If it's an image then send it to Glide.
                if (strpos($mimeType, 'image') !== false) {
                    $server = $glide->getServer();
                    $params = $request->query->all();

                    // The filter overwrites the params from get
                    if (!empty($filter)) {
                        $params = $glide->getFilters()[$filter] ?? [];
                    }

                    // The image was cropped manually by the user, so we force to render this version,
                    // no matter other crop parameters.
                    $crop = $resourceFile->getCrop();
                    if (!empty($crop)) {
                        $params['crop'] = $crop;
                    }

                    return $server->getImageResponse($filePath, $params);
                }
                break;
        }

        $stream = $this->fs->readStream($filePath);
        $response = new StreamedResponse(function () use ($stream): void {
            stream_copy_to_stream($stream, fopen('php://output', 'wb'));
        });
        $disposition = $response->headers->makeDisposition(
            $forceDownload ? ResponseHeaderBag::DISPOSITION_ATTACHMENT : ResponseHeaderBag::DISPOSITION_INLINE,
            $fileName
            //Transliterator::transliterate($fileName)
        );
        $response->headers->set('Content-Disposition', $disposition);
        $response->headers->set('Content-Type', $mimeType ?: 'application/octet-stream');

        return $response;
    }

    /**
     * @param string $fileType
     *
     * @return RedirectResponse|Response
     */
    private function createResource(Request $request, $fileType = 'file')
    {
        $tool = $request->get('tool');
        $type = $request->get('type');
        $resourceNodeParentId = $request->get('id');

        $repository = $this->getRepositoryFromRequest($request);

        $form = $repository->getForm($this->container->get('form.factory'));

        if ($fileType === 'file') {
            $form->add(
                'content',
                CKEditorType::class,
                [
                    'mapped' => false,
                    'config' => [
                        'filebrowserImageBrowseRoute' => 'editor_filemanager',
                        'filebrowserImageBrowseRouteParameters' => [
                            'tool' => $tool,
                            'type' => $type,
                            'cidReq' => $this->getCourse()->getCode(),
                            'id_session' => $this->getSessionId(),
                            'id' => $resourceNodeParentId,
                        ],
                    ],
                ]
            );
        }

        $course = $this->getCourse();
        $session = $this->getSession();
        // Default parent node is course.
        $parentNode = $course->getResourceNode();
        if (!empty($resourceNodeParentId)) {
            // Get parent node.
            $parentNode = $repository->getResourceNodeRepository()->find($resourceNodeParentId);
        }

        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $em = $this->getDoctrine()->getManager();

            /** @var CDocument $newResource */
            $newResource = $form->getData();

            $newResource
                ->setCourse($course)
                ->setSession($session)
                ->setFiletype($fileType)
                //->setTitle($title) // already added in $form->getData()
                ->setReadonly(false)
            ;

            $file = null;
            if ($fileType === 'file') {
                $content = $form->get('content')->getViewData();
                $newResource->setTitle($newResource->getTitle().'.html');
                $fileName = $newResource->getTitle();

                $handle = tmpfile();
                fwrite($handle, $content);
                $meta = stream_get_meta_data($handle);
                $file = new UploadedFile($meta['uri'], $fileName, null, null, true);
                $em->persist($newResource);
            }

            $resourceNode = $repository->createNodeForResource($newResource, $this->getUser(), $parentNode, $file);
            $em->persist($resourceNode);

            $repository->addResourceNodeToCourse(
                $resourceNode,
                ResourceLink::VISIBILITY_PUBLISHED,
                $course,
                $session,
                null
            );

            $em->flush();

            // Loops all sharing options
            /*foreach ($shareList as $share) {
                $idList = [];
                if (isset($share['search'])) {
                    $idList = explode(',', $share['search']);
                }

                $resourceRight = null;
                if (isset($share['mask'])) {
                    $resourceRight = new ResourceRight();
                    $resourceRight
                        ->setMask($share['mask'])
                        ->setRole($share['role'])
                    ;
                }

                // Build links
                switch ($share['sharing']) {
                    case 'everyone':
                        $repository->addResourceToEveryone(
                            $resourceNode,
                            $resourceRight
                        );
                        break;
                    case 'course':
                        $repository->addResourceToCourse(
                            $resourceNode,
                            $course,
                            $resourceRight
                        );
                        break;
                    case 'session':
                        $repository->addResourceToSession(
                            $resourceNode,
                            $course,
                            $session,
                            $resourceRight
                        );
                        break;
                    case 'user':
                        // Only for me
                        if (isset($share['only_me'])) {
                            $repository->addResourceOnlyToMe($resourceNode);
                        } else {
                            // To other users
                            $repository->addResourceToUserList($resourceNode, $idList);
                        }
                        break;
                    case 'group':
                        // @todo
                        break;
                }*/
            //}
            $em->flush();
            $this->addFlash('success', $this->trans('Saved'));

            return $this->redirectToRoute(
                'chamilo_core_resource_list',
                [
                    'id' => $resourceNodeParentId,
                    'tool' => $tool,
                    'type' => $type,
                    'cidReq' => $this->getCourse()->getCode(),
                    'id_session' => $this->getSessionId(),
                ]
            );
        }

        switch ($fileType) {
            case 'folder':
                $template = '@ChamiloTheme/Resource/new_folder.html.twig';
                break;
            case 'file':
                $template = '@ChamiloTheme/Resource/new.html.twig';
                break;
        }

        return $this->render(
            $template,
            [
                'form' => $form->createView(),
                'parent' => $resourceNodeParentId,
                'file_type' => $fileType,
            ]
        );
    }
}