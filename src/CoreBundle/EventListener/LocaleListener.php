<?php

/* For licensing terms, see /license.txt */

namespace Chamilo\CoreBundle\EventListener;

use Chamilo\CoreBundle\Entity\Course;
use Chamilo\CoreBundle\Manager\SettingsManager;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Class LocaleListener
 * Checks the portal listener depending of different settings:
 * platform, user, course.
 */
class LocaleListener implements EventSubscriberInterface
{
    protected $settingsManager;
    protected $defaultLocale;
    protected $paremeterBag;

    /**
     * LocaleListener constructor.
     *
     * @param string $defaultLocale
     */
    public function __construct($defaultLocale, SettingsManager $settingsManager, ParameterBagInterface $paremeterBag)
    {
        $this->defaultLocale = $defaultLocale;
        $this->settingsManager = $settingsManager;
        $this->paremeterBag = $paremeterBag;
    }

    public function onKernelRequest(RequestEvent $event)
    {
        $request = $event->getRequest();
        if (!$request->hasPreviousSession()) {
            return;
        }
        $installed = 1 == $this->paremeterBag->get('installed');

        if (!$installed) {
            return;
        }

        $loadFromDb = $request->getSession()->get('check_locale_from_db', true);

        if (false === $loadFromDb &&
            $request->getSession()->has('_locale') &&
            !empty($request->getSession()->get('_locale'))
        ) {
            $locale = $request->getSession()->get('_locale');
            $request->setLocale($locale);

            return true;
        }

        // Try to see if the locale has been set as a _locale routing parameter (from lang switcher)
        //if ($locale = $request->getSession('_locale')) {
        if ($loadFromDb) {
            $localeList = [];

            // 1. Check platform locale
            $platformLocale = $this->settingsManager->getSetting('language.platform_language');

            if (!empty($platformLocale)) {
                $localeList['platform_lang'] = $platformLocale;
            }
            // 2. Check user locale
            // _locale_user is set when user logins the system check UserLocaleListener
            $userLocale = $request->getSession()->get('_locale_user');

            if (!empty($userLocale)) {
                $localeList['user_profil_lang'] = $userLocale;
            }

            // 3. Check course locale
            $courseId = $request->get('cid');

            if (!empty($courseId)) {
                /** @var Course $course */
                $course = $request->getSession()->get('course');
                // 3. Check course locale
                /** @var Course $course */
                if (!empty($course)) {
                    $courseLocale = $course->getCourseLanguage();
                    if (!empty($courseLocale)) {
                        //$locale = $courseLocale;
                        $localeList['course_lang'] = $platformLocale;
                    }
                }
            }

            // 4. force locale if it was selected from the URL
            $localeFromUrl = $request->get('_locale');
            if (!empty($localeFromUrl)) {
                $localeList['user_selected_lang'] = $platformLocale;
            }

            $priorityList = [
                'language_priority_4',
                'language_priority_3',
                'language_priority_2',
                'language_priority_1',
            ];

            $locale = '';
            foreach ($priorityList as $setting) {
                $priority = $this->settingsManager->getSetting("language.$setting");
                if (!empty($priority) && isset($localeList[$priority])) {
                    $locale = $localeList[$priority];

                    break;
                }
            }

            if (empty($locale)) {
                // Use default order
                $priorityList = [
                    'platform_lang',
                    'user_profil_lang',
                    'course_lang',
                    'user_selected_lang',
                ];
                foreach ($priorityList as $setting) {
                    if (isset($localeList[$setting])) {
                        $locale = $localeList[$setting];
                    }
                }
            }

            if (empty($locale)) {
                $locale = $this->defaultLocale;
            }

            // if no explicit locale has been set on this request, use one from the session
            $request->setLocale($locale);
            $request->getSession()->set('_locale', $locale);
            $request->getSession()->set('check_locale_from_db', false);
        }
    }

    /**
     * @return array
     */
    public static function getSubscribedEvents()
    {
        return [
            // must be registered before the default Locale listener
            KernelEvents::REQUEST => [['onKernelRequest', 15]],
        ];
    }
}
