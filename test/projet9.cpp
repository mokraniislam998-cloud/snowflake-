#include <GL/glut.h>
#include <cstdlib>

float rotX = 0.0f, rotY = 0.0f;
float zoom = -5.0f;

int lastX, lastY;
bool leftButton = false;

bool animYPos = false;
bool animYNeg = false;
bool videoRunning = true;

void init() {
    glEnable(GL_DEPTH_TEST);
    glClearColor(0.1f, 0.1f, 0.1f, 1.0f);
}

void display() {
    glClear(GL_COLOR_BUFFER_BIT | GL_DEPTH_BUFFER_BIT);

    glMatrixMode(GL_PROJECTION);
    glLoadIdentity();
    glFrustum(-1, 1, -1, 1, 1, 100);

    glMatrixMode(GL_MODELVIEW);
    glLoadIdentity();

    glTranslatef(0.0f, 0.0f, zoom);

    glRotatef(rotX, 1, 0, 0);
    glRotatef(rotY, 0, 1, 0);

    if (animYPos) rotY += 0.2f;
    if (animYNeg) rotY -= 0.2f;

    glColor3f(0.8f, 0.3f, 0.3f);
    glutSolidTeapot(1.0);

    glutSwapBuffers();
}

void mouse(int button, int state, int x, int y) {
    if (button == GLUT_LEFT_BUTTON) {
        leftButton = (state == GLUT_DOWN);
        lastX = x;
        lastY = y;
    }
}

void motion(int x, int y) {
    if (leftButton) {
        rotY += (x - lastX);
        rotX += (y - lastY);
        lastX = x;
        lastY = y;
        glutPostRedisplay();
    }
}

void keyboard(unsigned char key, int x, int y) {
    switch (key) {
        case 'a': animYPos = true; break;
        case 'A': animYNeg = true; break;
        case 's': videoRunning = !videoRunning; break;
        case 32:
            rotX = rotY = 0.0f;
            zoom = -5.0f;
            break;
        case 27:
            exit(0);
    }
}

void keyboardUp(unsigned char key, int x, int y) {
    if (key == 'a') animYPos = false;
    if (key == 'A') animYNeg = false;
}

int main(int argc, char** argv) {
    glutInit(&argc, argv);
    glutInitDisplayMode(GLUT_DOUBLE | GLUT_RGB | GLUT_DEPTH);
    glutInitWindowSize(800, 600);
    glutCreateWindow("Augmentation 3D - Tutorial 9");

    init();

    glutDisplayFunc(display);
    glutMouseFunc(mouse);
    glutMotionFunc(motion);
    glutKeyboardFunc(keyboard);
    glutKeyboardUpFunc(keyboardUp);
    glutIdleFunc(display);

    glutMainLoop();
    return 0;
}
