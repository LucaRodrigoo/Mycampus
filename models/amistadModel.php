<?php
require_once __DIR__ . "/../config/database.php";

class Amistad {
    private $conn;
    private $table_solicitudes = "solicitudes";
    private $table_notificaciones = "notificaciones";

    public function __construct($db) {
        $this->conn = $db;
    }

    
    public function enviarSolicitud($id_solicitante, $id_receptor) {
        
        $query = "SELECT * FROM {$this->table_solicitudes} 
                  WHERE (id_solicitante = :id_solicitante AND id_receptor = :id_receptor)
                  OR (id_solicitante = :id_receptor AND id_receptor = :id_solicitante)";
        $stmt = $this->conn->prepare($query);
        $stmt->execute(['id_solicitante' => $id_solicitante, 'id_receptor' => $id_receptor]);

        if ($stmt->rowCount() > 0) {
            return "Ya existe una solicitud o son amigos.";
        }

        // Insertar la solicitud
        $query = "INSERT INTO {$this->table_solicitudes} (id_solicitante, id_receptor, estado) 
                  VALUES (:id_solicitante, :id_receptor, 'pendiente')";
        $stmt = $this->conn->prepare($query);
        $stmt->execute(['id_solicitante' => $id_solicitante, 'id_receptor' => $id_receptor]);

        // Crear la notificación
        $mensaje = "Te ha enviado una nueva solicitud de amistad.";
        $query = "INSERT INTO {$this->table_notificaciones} (id_usuario, origen_id, tipo, mensaje) 
                  VALUES (:id_receptor, :origen_id,'solicitud_amistad', :mensaje)";
        $stmt = $this->conn->prepare($query);
        $stmt->execute(['id_receptor' => $id_receptor, 'origen_id' =>$id_solicitante,'mensaje' => $mensaje]);

        return "Solicitud enviada con éxito.";
    }

    // Obtener notificaciones
    public function obtenerNotificacionesAmistad($usuario_id) {
        $query = "SELECT 
                    n.id,
                    n.id_usuario,
                    n.origen_id,
                    u.nombre AS nombre_origen,
                    n.tipo,
                    n.mensaje,
                    n.fecha
                FROM 
                    notificaciones n
                JOIN 
                    users u ON n.origen_id = u.id
                WHERE 
                    n.id_usuario = :usuario_id
                ORDER BY 
                    n.fecha DESC;";
    
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":usuario_id", $usuario_id, PDO::PARAM_INT);
        $stmt->execute();
    
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    
    

    public function obtenerSolicitudesPendientes($id_usuario) {
        $query = "
            SELECT s.id, u.id AS id_solicitante, u.nombre, u.foto_perfil
            FROM solicitudes s
            JOIN users u ON s.id_solicitante = u.id
            WHERE s.id_receptor = :id_usuario AND s.estado = 'pendiente'
        ";
    
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":id_usuario", $id_usuario, PDO::PARAM_INT);
        $stmt->execute();
    
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }



    
    public function aceptarSolicitud($id_solicitud, $nombreUsuarioActual) {
        
        $query = "SELECT id_solicitante, id_receptor FROM solicitudes WHERE id = :id_solicitud";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":id_solicitud", $id_solicitud, PDO::PARAM_INT);
        $stmt->execute();
        $solicitud = $stmt->fetch(PDO::FETCH_ASSOC);
    
        if ($solicitud) {
            try {
                $this->conn->beginTransaction();
    
                
                $queryInsert = "INSERT INTO amigos (usuario_id, amigo_id) VALUES (:usuario_id, :amigo_id)";
                $stmtInsert = $this->conn->prepare($queryInsert);
                $stmtInsert->bindParam(":usuario_id", $solicitud['id_solicitante'], PDO::PARAM_INT);
                $stmtInsert->bindParam(":amigo_id", $solicitud['id_receptor'], PDO::PARAM_INT);
                $stmtInsert->execute();
    
                
                $mensaje = "¡aceptó tu solicitud de amistad!";
                $queryNotificacion = "INSERT INTO notificaciones (id_usuario,origen_id, tipo, mensaje) 
                                      VALUES (:usuario_id, :origen_id, 'amistad_aceptada', :mensaje)";
                $stmtNotificacion = $this->conn->prepare($queryNotificacion);
                $stmtNotificacion->bindParam(":usuario_id", $solicitud['id_solicitante'], PDO::PARAM_INT);
                $stmtNotificacion->bindParam(":origen_id", $solicitud['id_receptor'], PDO::PARAM_INT);
                $stmtNotificacion->bindParam(":mensaje", $mensaje, PDO::PARAM_STR);
                $stmtNotificacion->execute();
    
                
                $queryUpdate = "UPDATE solicitudes SET estado = 'aceptado' WHERE id = :id_solicitud";
                $stmtUpdate = $this->conn->prepare($queryUpdate);
                $stmtUpdate->bindParam(":id_solicitud", $id_solicitud, PDO::PARAM_INT);
                $stmtUpdate->execute();
    
                $this->conn->commit();
                return true;
            } catch (Exception $e) {
                $this->conn->rollBack();
                return false;
            }
        }
        return false;
    }
    
    
    public function rechazarSolicitud($id_solicitud) {
        try {
            $query = "DELETE FROM solicitudes WHERE id = :id_solicitud";
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(":id_solicitud", $id_solicitud, PDO::PARAM_INT);
            $stmt->execute();
            
            return $stmt->rowCount() > 0;
        } catch (PDOException $e) {
            error_log("Error en rechazarSolicitud: " . $e->getMessage());
            return false;
        }
    }
    

    public function obtenerMisAmigos($id_usuario) {
        $query = "SELECT u.id, u.nombre 
                  FROM amigos a
                  JOIN users u ON (a.amigo_id = u.id OR a.usuario_id = u.id)
                  WHERE (a.usuario_id = :id_usuario OR a.amigo_id = :id_usuario)
                  AND u.id != :id_usuario";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":id_usuario", $id_usuario, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    public function obtenerNombreAmigo($idAmigo) {
        $query = "SELECT nombre FROM users WHERE id = :idAmigo";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':idAmigo', $idAmigo);
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ? $result['nombre'] : null;
    }

    public function eliminarAmigo($id_usuario, $id_amigo) {
        try {
            $this->conn->beginTransaction();
    
            // Eliminar la relación de amistad
            $queryAmistad = "DELETE FROM amigos 
                             WHERE (usuario_id = :id_usuario AND amigo_id = :id_amigo)
                                OR (usuario_id = :id_amigo AND amigo_id = :id_usuario)";
            $stmtAmistad = $this->conn->prepare($queryAmistad);
            $stmtAmistad->bindParam(":id_usuario", $id_usuario, PDO::PARAM_INT);
            $stmtAmistad->bindParam(":id_amigo", $id_amigo, PDO::PARAM_INT);
            $stmtAmistad->execute();
    
            // Eliminar cualquier solicitud de amistad entre ambos
            $querySolicitud = "DELETE FROM solicitudes
                               WHERE (id_solicitante = :id_usuario AND id_receptor = :id_amigo)
                                  OR (id_solicitante = :id_amigo AND id_receptor = :id_usuario)";
            $stmtSolicitud = $this->conn->prepare($querySolicitud);
            $stmtSolicitud->bindParam(":id_usuario", $id_usuario, PDO::PARAM_INT);
            $stmtSolicitud->bindParam(":id_amigo", $id_amigo, PDO::PARAM_INT);
            $stmtSolicitud->execute();
    
            $this->conn->commit();
            return true;
        } catch (Exception $e) {
            $this->conn->rollBack();
            return false;
        }
    }
    
    
    
    
    
}
?>
