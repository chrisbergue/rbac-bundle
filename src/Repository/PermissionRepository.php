<?php

namespace PhpRbacBundle\Repository;

use Doctrine\ORM\ORMException;
use PhpRbacBundle\Entity\Permission;
use PhpRbacBundle\Entity\RoleInterface;
use Doctrine\ORM\Query\ResultSetMapping;
use Doctrine\ORM\OptimisticLockException;
use Doctrine\Persistence\ManagerRegistry;
use PhpRbacBundle\Entity\PermissionInterface;
use PhpRbacBundle\Core\Manager\NodeManagerInterface;
use PhpRbacBundle\Exception\RbacPermissionNotFoundException;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Bridge\Doctrine\Types\UlidType;

/**
 * @method Permission|null find($id, $lockMode = null, $lockVersion = null)
 * @method Permission|null findOneBy(array $criteria, array $orderBy = null)
 * @method Permission[]    findAll()
 * @method Permission[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class PermissionRepository extends ServiceEntityRepository implements NestedSetInterface
{
    use NodeEntityTrait;

    private string $roleTableName;

    private string $tableName;

    public function __construct(ManagerRegistry $registry, string $entityClass)
    {
        parent::__construct($registry, $entityClass);

        $this->roleTableName = $this->getEntityManager()
            ->getClassMetadata(RoleInterface::class)
            ->getTableName();
        $this->tableName = $this->getClassMetadata()
            ->getTableName();
    }

    public function initTable()
    {
        $sql = "SET FOREIGN_KEY_CHECKS = 0; TRUNCATE rbac_role_rbac_permission; TRUNCATE {$this->tableName}; SET FOREIGN_KEY_CHECKS = 1";
        $this->getEntityManager()
            ->getConnection()
            ->executeQuery($sql);
        $sql = "INSERT INTO {$this->tableName} (id, code, description, tree_left, tree_right) VALUES (1, 'root', 'root', 0, 1)";
        $this->getEntityManager()
            ->getConnection()
            ->executeQuery($sql);
    }


    /**
     * @throws ORMException
     * @throws OptimisticLockException
     */
    public function add(Permission $entity, bool $flush = true): void
    {
        $this->getEntityManager()
            ->persist($entity);
        if ($flush) {
            $this->getEntityManager()
                ->flush();
        }
    }

    /**
     * @throws ORMException
     * @throws OptimisticLockException
     */
    public function remove(Permission $entity, bool $flush = true): void
    {
        $this->getEntityManager()
            ->remove($entity);
        if ($flush) {
            $this->getEntityManager()
                ->flush();
        }
    }

    public function getPathId(string $path): int
    {
        return $this->pathId($path, RbacPermissionNotFoundException::class);
    }

    public function getById(int $nodeId): Permission
    {
        $node = $this->find($nodeId);
        if (empty($node)) {
            throw new RbacPermissionNotFoundException("Permission {$nodeId} not found");
        }

        return $node;
    }

    public function getPath(int $nodeId): array
    {
        return $this->getPathFunc($nodeId, RbacPermissionNotFoundException::class);
    }

    public function getChildren(int $nodeId): array
    {
        $sql = "
            SELECT
                node.*,
                (COUNT(parent.id)-1 - (sub_tree.innerDepth )) AS depth
            FROM
                {$this->tableName} as node,
                {$this->tableName} as parent,
                {$this->tableName} as sub_parent,
                (
                    SELECT
                        node.id,
                        (COUNT(parent.id) - 1) AS innerDepth
                    FROM
                        {$this->tableName} AS node,
                        {$this->tableName} AS parent
                    WHERE
                        node.tree_left BETWEEN parent.tree_left AND parent.tree_right
                        AND (node.id = :nodeId)
                    GROUP BY
                        node.id
                    ORDER BY
                        node.tree_left
                ) AS sub_tree
            WHERE
                node.tree_left BETWEEN parent.tree_left AND parent.tree_right
                AND node.tree_left BETWEEN sub_parent.tree_left AND sub_parent.tree_right
                AND sub_parent.id = sub_tree.id
            GROUP BY
                node.id
            HAVING
                depth = 1
            ORDER BY
                node.tree_left
        ";

        $rsm = new ResultSetMapping();
        $rsm->addEntityResult($this->getClassName(), 'node');
        $query = $this->getEntityManager()
            ->createNativeQuery($sql, $rsm);
        $query->setParameter(':nodeId', $nodeId);

        $result = $query->getResult();

        if (empty($result)) {
            throw new RbacPermissionNotFoundException();
        }

        return $result;
    }

    public function hasPermission(int $permissionId, $user): bool
    {
        $userRoleTable = '';
        $userRoles = $user->getRbacRoles();
        $userIdName = null;
        if (!empty($userRoles)) {
            $userRoleTable = $this->getEntityManager()
                ->getClassMetadata(get_class($user))
                ->getAssociationMapping('rbacRoles')["joinTable"]["name"];

            foreach ($this->getEntityManager()
                         ->getClassMetadata(get_class($user))
                         ->getAssociationMapping('rbacRoles')["joinTable"] ["joinColumns"] as $joinColum){
                if($joinColum["name"] !=='role_id'){
                    $userIdName=   $joinColum["name"];
                    continue;
                }

            }

        } else {
            return false;
        }
        if (empty($userRoleTable)||empty($userIdName)) {
            return false;
        }

        $pdo = $this->getEntityManager()
            ->getConnection();

        $sql = "
            SELECT
                COUNT(*) AS result
            FROM
                {$userRoleTable}
            INNER JOIN
                {$this->roleTableName} AS TRdirect ON TRdirect.ID={$userRoleTable}.role_id
            INNER JOIN
                {$this->roleTableName} AS TR ON TR.tree_left BETWEEN TRdirect.tree_left AND TRdirect.tree_right
            INNER JOIN
                ({$this->tableName} AS TPdirect
                    INNER JOIN
                    {$this->tableName} AS TP ON TPdirect.tree_left BETWEEN TP.tree_left AND TP.tree_right
                    INNER JOIN
                        rbac_role_rbac_permission AS TRel ON TP.ID=TRel.permission_id
                ) ON TR.ID = TRel.role_id
            WHERE
                {$userRoleTable}.{$userIdName} = :userId
                AND TPdirect.id = :permissionId
        ";
        $query = $pdo->prepare($sql);
        $query->bindValue(":userId", $user->getId(), UlidType::NAME);
        $query->bindValue(":permissionId", $permissionId);
        $stmt = $query->executeQuery();

        if ($stmt->rowCount() == 0) {
            return false;
        }

        $row = $stmt->fetchAssociative();
        return $row['result'] >= 1;
    }

    public function addNode(string $code, string $description, int $parentId = NodeManagerInterface::ROOT_ID): PermissionInterface
    {
        /** @var PermissionInterface $node */
        $node = $this->updateForAdd($parentId, $this->getClassName(), $code, $description);

        return $node;
    }
}
