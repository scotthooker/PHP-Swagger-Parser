<?php
namespace Swagger;

use Swagger\Exception as SwaggerException;

class SchemaResolver
{
    protected $document;
    
    protected $relativeResolvers;
    
    /**
     * Construct a new schema resolver
     *
     * @param Document $document - The underlying schema document
     * @param array $relativeResolvers - An array of relative resolvers or types
     */
    public function __construct(
        Document $document,
        $relativeResolvers = []
    )
    {
        $this->setDocument($document);
        $this->setRelativeResolvers($relativeResolvers);
    }
    
    /**
     * Parse a type-object with data into its respective structure
     *
     * @param Object\TypeObjectInterface $type - The schema for the type
     * @param \stdClass $data - The input data
     * @return SchemaObject|array
     */
    public function parseType(Object\TypeObjectInterface $type, $data)
    {
        if($type instanceof Object\ReferentialInterface && $type->hasRef()) {
            $objectType = $type->getRef();
            $type = $this->resolveReference($type);
        } else {
            try {
                $objectType = $type->getType();
            } catch(SwaggerException\MissingDocumentPropertyException $e) {
                $objectType = null;
            }
        }
        
        if($objectType == 'array') {
            $schemaObject = [];
            
            $arrayItemType = $type->getItems();
            
            foreach($data as $key => $value) {
                $schemaObject[$key] = $this->parseType($arrayItemType, $value);
            }
        } elseif($type instanceof Object\Schema) {
            $schemaObject = new SchemaObject($objectType);
        
            foreach(array_keys(get_object_vars($data)) as $name => $propertyKey) {
                try {
                    $propertySchema = $this->findSchemaForProperty($type, $propertyKey);
                } catch(SwaggerException\MissingDocumentPropertyException $e) {
                    throw (new SwaggerException\UndefinedPropertySchemaException)
                        ->setPropertyName($name)
                        ->setSchema($type);
                }
            
                $propertyValue = $this->parseType(
                    $propertySchema,
                    $data->$propertyKey
                );
                
                $schemaObject->setProperty($propertyKey, $propertyValue);
            }
        } else {
            $schemaObject = $data;
        }
        
        return $schemaObject;
    }
    
    public function findTypeAtPointer(Json\Pointer $pointer)
    {
        switch($pointer->getSegment(0)) {
            case 'paths':
                return $this->getDocument()
                    ->getPaths()
                    ->getPath($pointer->getSegment(1));
            case 'definitions':
                return $this->getDocument()
                    ->getDefinitions()
                    ->getDefinition($pointer->getSegment(1));
            case 'parameters':
                return $this->getDocument()
                    ->getParameters()
                    ->getParameter($pointer->getSegment(1));
            case 'responses':
                return $this->getDocument()
                    ->getResponses()
                    ->getHttpStatusCode($pointer->getSegment(1));
            case 'securityDefinitions':
                return $this->getDocument()
                    ->getSecurityDefinitions()
                    ->getDefinition($pointer->getSegment(1));
            default:
                throw new \OutOfBoundsException("The specified type path '{$pointer->getSegment(0)}' is not supported");
        }
    }
    
    public function findSchemaForOperationResponse(Object\Operation $operation, $statusCode)
    {
        try {
            $response = $operation->getResponses()
                ->getHttpStatusCode($statusCode);
        } catch(SwaggerException\MissingDocumentPropertyException $e) {
            // This status is not defined, but we can hope for an operation default
            try {
                $response = $operation->getResponses()
                    ->getDefault();
            } catch(SwaggerException\MissingDocumentPropertyException $e) {
                throw (new SwaggerException\UndefinedOperationResponseSchemaException)
                    ->setOperationId($operation->getOperationId())
                    ->setStatusCode($statusCode);
            }
        }
        
        try {
            $responseSchema = $response->getSchema();
        } catch(SwaggerException\MissingDocumentPropertyException $e) {
            throw (new SwaggerException\UndefinedOperationResponseSchemaException)
                ->setOperationId($operation->getOperationId())
                ->setStatusCode($statusCode);
        }
        
        return $responseSchema;
    }
    
    protected function findSchemaForProperty(Object\Schema $schema, $property)
    {
        try {
            $propertySchema = $schema->getProperties()
                ->getProperty($property);
        } catch(SwaggerException\MissingDocumentPropertyException $e) {
            try {
                $propertySchema = $this->findPropertyInAllOf($schema, $property);
            } catch(SwaggerException\MissingDocumentPropertyException $e) {
                $propertySchema = $this->findPropertyInAdditionalProperties($schema, $property);
            }
        }
        
        return $propertySchema;
    }
    
    protected function findPropertyInAllOf(Object\Schema $schema, $property)
    {
        $composingSchemas = $schema->getAllOf();
                
        foreach($composingSchemas as $composingSchema) {
            $composingSchema = $this->resolveReference($composingSchema);
            
            try {
                $propertySchema = $this->findSchemaForProperty($composingSchema, $property);
            } catch(SwaggerException\MissingDocumentPropertyException $e) {
                // This one doesn't have it, but let's try the rest
            }
        }
        
        if(empty($propertySchema)) {
            throw (new SwaggerException\MissingDocumentPropertyException)
                ->setDocumentProperty($property);
        }
        
        return $propertySchema;
    }
    
    protected function findPropertyInAdditionalProperties(Object\Schema $schema, $property)
    {
        $additionalProperties = $this->resolveReference(
            $schema->getAdditionalProperties()
        );
        
        $propertySchema = $this->findSchemaForProperty($additionalProperties, $property);
        
        return $propertySchema;
    }
    
    public function resolveReference(Object\ReferentialInterface $reference)
    {
        if(!$reference->hasRef()) {
            return $reference;
        }
    
        $ref = $reference->getRef();
    
        if($ref->hasUri()) {
            $uri = $ref->getUri();
            if(!$this->hasRelativeResolver($uri)) {
                throw (new SwaggerException\RelativeResolverUnavailableException)
                    ->setUri($uri);
            }
            
            $resolver = $this->getRelativeResolver($uri);
            
            if($resolver instanceof Object\AbstractObject) {
                return $resolver;
            } elseif(!($resolver instanceof SchemaResolver)) {
                throw new \UnexpectedValueException('Relative resolvers much be a SchemaResolver or a resolved type');
            }
        } else {
            $resolver = $this;
        }
        
        return $resolver->findTypeAtPointer($ref->getPointer());
    }
    
    protected function getDocument()
    {
        return $this->document;
    }
    
    protected function setDocument($document)
    {
        $this->document = $document;
        return $this;
    }
    
    protected function setRelativeResolvers($relativeResolvers)
    {
        $this->relativeResolvers = $relativeResolvers;
        return $this;
    }
    
    protected function hasRelativeResolver($path)
    {
        return !empty($this->relativeResolvers[$path]);
    }
    
    protected function getRelativeResolver($path)
    {
        return $this->relativeResolvers[$path];
    }
}
