# 📊 Sistema de Control y Auditoría de Métricas

Una plataforma web robusta desarrollada en PHP y MySQL, diseñada para centralizar, automatizar y auditar el cumplimiento de metas operativas y proyectos de formación (ej. *Mujeres: Equidad y empleo*). 

Este sistema elimina el seguimiento manual en hojas de cálculo, ofreciendo un entorno seguro con roles de usuario, trazabilidad profunda y una interfaz moderna.

## 🚀 Características Principales

* **🎯 Gestión Avanzada de Jornadas:** Registro diario de métricas con control de "Apertura (In)" y "Cierre (Out)". Permite cargas retroactivas para periodos anteriores.
* **🤖 Cierres y Consolidación Automatizada:** Un script inteligente (cron job/lógica interna) que detecta el cambio de mes y consolida automáticamente los registros "Por Confirmar" (Amarillos) a "Confirmadas" (Verdes), cuadrando los números globales.
* **📂 Trazabilidad en Google Drive:** Integración con la API de Google Drive para enlazar y auditar archivos de Excel subidos por el equipo, controlando estados como: *Cargado, En Revisión, Devuelto y Aprobado*.
* **🕵️‍♂️ Auditoría Inmutable (Nivel Super Admin):** Sistema de "Soft Delete" (borrado lógico). Ningún registro se elimina realmente de la base de datos; el Super Admin tiene acceso a una bitácora profunda donde ve quién creó, editó o eliminó cada cifra y en qué fecha exacta.
* **📈 Reportería y Exportación:** * Generador automático de reportes en texto plano listo para copiar y pegar (ideal para resúmenes de WhatsApp o correo).
    * Exportación limpia a Excel (`.xls`) de las estadísticas diarias.
* **🔐 Gestión de Accesos:** Diferentes vistas según el rol (Usuario, Admin, Super Admin). El Super Admin puede forzar el cambio de contraseñas del equipo desde la interfaz.
* **🌗 Interfaz UI/UX Moderna:** Diseño 100% responsivo (móvil y tablet) construido con Tailwind CSS, con soporte nativo para Modo Oscuro/Claro y notificaciones interactivas (SweetAlert2).

## 🛠️ Stack Tecnológico

* **Backend:** PHP 8+ (Lógica pura, sin frameworks pesados para máxima velocidad).
* **Base de Datos:** MySQL (Consultas optimizadas y diseño relacional).
* **Frontend:** HTML5, Tailwind CSS (v3), JavaScript (Vanilla).
* **Librerías Adicionales:** Google APIs Client Library (PHP), SweetAlert2 (JS).

## 💡 Flujo de Trabajo (Estados de la data)

1.  **En Curso:** El usuario abre una jornada pero aún no la cierra.
2.  **Por Confirmar:** El usuario cierra la jornada o hace una carga retroactiva. El dato suma a la estadística general, pero queda a la espera del cierre de mes.
3.  **Confirmada:** El sistema consolida el mes o el archivo es aprobado por auditoría. El dato es oficial e inamovible.

## ⚙️ Configuración (Setup)

Para correr este proyecto localmente o en tu servidor (cPanel/VPS):

1. Clona este repositorio.
2. Configura los parámetros de tu base de datos en `db_connection.php`.
3. Ejecuta las migraciones o importa el archivo `.sql` inicial (si aplica).
4. Asegúrate de tener permisos de escritura en la carpeta `mis_sesiones_privadas` para la persistencia de usuarios.
5. *(Opcional)* Coloca tu archivo `credenciales.json` de Google Cloud Platform en la raíz para habilitar la lectura de Google Drive.
