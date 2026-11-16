<?php

declare(strict_types=1);

namespace App\Module\Admin\Controller;

use App\Infrastructure\Doctrine\Entity\SchoolHoliday;
use App\Shared\Enum\RoleEnum;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Ramsey\Uuid\Uuid;

/**
 * Fork: school holidays for the team calendar.
 *
 * @extends AppAbstractCrudController<SchoolHoliday>
 */
#[AdminRoute(path: '/school-holidays', name: 'app_school_holiday')]
class SchoolHolidayCrudController extends AppAbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return SchoolHoliday::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setPageTitle(Crud::PAGE_INDEX, 'crud.school_holiday.index.title')
            ->setPageTitle(Crud::PAGE_EDIT, 'crud.school_holiday.edit.title')
            ->setPageTitle(Crud::PAGE_NEW, 'crud.school_holiday.create.title')
            ->setPageTitle(Crud::PAGE_DETAIL, 'crud.school_holiday.detail.title')
            ->setEntityPermission(RoleEnum::Admin->value)
            ->setDefaultSort(['startDate' => 'ASC']);
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions
            ->update(
                Crud::PAGE_INDEX,
                Action::NEW,
                static fn (Action $action): Action => $action->setLabel('crud.school_holiday.action.new'),
            );
    }

    public function configureFields(string $pageName): iterable
    {
        yield TextField::new('name', 'crud.school_holiday.field.name');
        yield DateField::new('startDate', 'crud.school_holiday.field.start');
        yield DateField::new('endDate', 'crud.school_holiday.field.end')
            ->setHelp('crud.school_holiday.help.end');
        yield TextField::new('groupName', 'crud.school_holiday.field.group')
            ->setHelp('crud.school_holiday.help.group');
    }

    public function createEntity(string $entityFqcn): SchoolHoliday
    {
        $today = new \DateTimeImmutable('today');

        return new SchoolHoliday(Uuid::uuid4(), 'Schulferien BFS', '', $today, $today);
    }
}
