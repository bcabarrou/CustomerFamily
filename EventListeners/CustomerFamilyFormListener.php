<?php
/*************************************************************************************/
/*      This file is part of the module CustomerFamily                               */
/*                                                                                   */
/*      Copyright (c) OpenStudio                                                     */
/*      email : dev@thelia.net                                                       */
/*      web : http://www.thelia.net                                                  */
/*                                                                                   */
/*      For the full copyright and license information, please view the LICENSE.txt  */
/*      file that was distributed with this source code.                             */
/*************************************************************************************/

namespace CustomerFamily\EventListeners;

use CustomerFamily\CustomerFamily;
use CustomerFamily\Model\CustomerFamilyQuery;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Validator\Constraints;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Validator\ExecutionContextInterface;
use Thelia\Action\BaseAction;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Core\Event\TheliaFormEvent;
use Thelia\Core\Translation\Translator;

class CustomerFamilyFormListener extends BaseAction implements EventSubscriberInterface
{

    /**
     * 'thelia_customer_create' is the name of the form used to create Customers (Thelia\Form\CustomerCreateForm).
     */
    const THELIA_CUSTOMER_CREATE_FORM_NAME = 'thelia_customer_create';

    const CUSTOMER_FAMILY_CODE_FIELD_NAME = 'customer_family_code';

    const CUSTOMER_FAMILY_SIRET_FIELD_NAME = 'siret';

    const CUSTOMER_FAMILY_VAT_FIELD_NAME = 'vat';

    /** @var \Thelia\Core\HttpFoundation\Request */
    protected $request;

    /**
     * @param Request $request
     */
    public function __construct(Request $request)
    {
        $this->request = $request;
    }

    /**
     * Returns an array of event names this subscriber wants to listen to.
     *
     * The array keys are event names and the value can be:
     *
     *  * The method name to call (priority defaults to 0)
     *  * An array composed of the method name to call and the priority
     *  * An array of arrays composed of the method names to call and respective
     *    priorities, or 0 if unset
     *
     * For instance:
     *
     *  * array('eventName' => 'methodName')
     *  * array('eventName' => array('methodName', $priority))
     *  * array('eventName' => array(array('methodName1', $priority), array('methodName2'))
     *
     * @return array The event names to listen to
     *
     * @api
     */
    public static function getSubscribedEvents()
    {
        return array(
            TheliaEvents::FORM_AFTER_BUILD.'.'.self::THELIA_CUSTOMER_CREATE_FORM_NAME => array('addCustomerFamilyFields', 128),
        );
    }

    /**
     * Callback used to add some fields to the Thelia's CustomerCreateForm.
     * It add two fields : one for the SIRET number and one for VAT.
     * @param TheliaFormEvent $event
     */
    public function addCustomerFamilyFields(TheliaFormEvent $event)
    {
        // Retrieving CustomerFamily choices
        $customerFamilyChoices = array();

        /** @var \CustomerFamily\Model\CustomerFamily $customerFamily */
        foreach (CustomerFamilyQuery::create()->find() as $customerFamily) {
            $customerFamilyChoices[$customerFamily->getCode()] = self::trans($customerFamily->getTitle());
        }


        // Building additional fields
        $event->getForm()->getFormBuilder()
            ->add(
                self::CUSTOMER_FAMILY_CODE_FIELD_NAME,
                'choice',
                array(
                    'constraints' => array(
                        new Constraints\Callback(array('methods' => array(
                            array($this, 'checkCustomerFamily')
                        ))),
                        new Constraints\NotBlank(),
                    ),
                    'choices' => $customerFamilyChoices,
                    'empty_data' => false,
                    'required' => false,
                    'label' => self::trans('Customer family'),
                    'label_attr' => array(
                        'for' => 'customer_family_id',
                    ),
                    'mapped' => false,
                )
            )
            ->add(
                self::CUSTOMER_FAMILY_SIRET_FIELD_NAME,
                'text',
                array(
                    'constraints' => array(
                        new Constraints\Callback(array("methods" => array(
                            array($this, "checkParticularInformations")
                        )))
                    ),
                    'required' => false,
                    'empty_data' => false,
                    'label' => self::trans('Siret number'),
                    'label_attr' => array(
                        'for' => 'siret'
                    ),
                    'mapped' => false,
                )
            )
            ->add(
                self::CUSTOMER_FAMILY_VAT_FIELD_NAME,
                'text',
                array(
                    'constraints' => array(
                        new Constraints\Callback(array("methods" => array(
                            array($this, "checkParticularInformations")
                        )))
                    ),
                    'required' => false,
                    'empty_data' => false,
                    'label' => self::trans('Vat'),
                    'label_attr' => array(
                        'for' => 'vat'
                    ),
                    'mapped' => false,
                )
            )
        ;
    }

    /**
     * Validate a field only if the customer family is valid
     *
     * @param string                    $value
     * @param ExecutionContextInterface $context
     */
    public function checkCustomerFamily($value, ExecutionContextInterface $context)
    {
        if (CustomerFamilyQuery::create()->filterByCode($value)->count() == 0) {
            $context->addViolation(self::trans('The customer family is not valid'));
        }
    }

    /**
     * Validate a field only if vat and siret are not empty if the customer family is professional
     *
     * @param string                    $value
     * @param ExecutionContextInterface $context
     */
    public function checkParticularInformations($value, ExecutionContextInterface $context)
    {
        $form = $this->request->request->get(self::THELIA_CUSTOMER_CREATE_FORM_NAME);

        if (is_null($form) or !array_key_exists(self::CUSTOMER_FAMILY_CODE_FIELD_NAME, $form)) {
            return;
        }

        switch ($form[self::CUSTOMER_FAMILY_CODE_FIELD_NAME]) {
            case CustomerFamily::CUSTOMER_FAMILY_PARTICULAR:
                // Don't care about additional fields => continue
                break;

            case CustomerFamily::CUSTOMER_FAMILY_PROFESSIONAL:
                // Additional fields should not be empty
                $blankFields = array(
                    isset($form[self::CUSTOMER_FAMILY_SIRET_FIELD_NAME]) ? (strlen($form[self::CUSTOMER_FAMILY_SIRET_FIELD_NAME]) === 0) : true,
                    isset($form[self::CUSTOMER_FAMILY_VAT_FIELD_NAME]) ? (strlen($form[self::CUSTOMER_FAMILY_VAT_FIELD_NAME]) === 0) : true
                );

                if (in_array(true, $blankFields)) {
                    // A field is blank => violation
                    $context->addViolation(self::trans("This field can't be empty"));
                }
                break;

            default:
                break;
        }
    }

    /**
     * Utility for translations
     * @param $id
     * @param array $parameters
     * @return string
     */
    protected static function trans($id, array $parameters = array())
    {
        return Translator::getInstance()->trans($id, $parameters, CustomerFamily::MESSAGE_DOMAIN);
    }
}
